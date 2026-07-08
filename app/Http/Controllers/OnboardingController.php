<?php

namespace App\Http\Controllers;

use App\Enums\SourceProvider;
use App\Http\Requests\StoreOnboardingRequest;
use App\Jobs\RecheckPendingDomains;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Source;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\Providers\DomainOnboardingException;
use App\Services\Providers\EmailProviderFactory;
use App\Support\ProjectContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OnboardingController extends Controller
{
    public function __construct(private EmailProviderFactory $providers) {}

    public function entry(Request $request, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $project = $context->projectFor($request->user());

        if (! $this->isComplete($context, $workspace, $project)) {
            if ($this->hasStartedSetup($context, $workspace, $project)) {
                return redirect($context->sectionPath($project, 'setup'));
            }

            return to_route('onboarding');
        }

        if (blank($workspace->onboarded_at)) {
            $workspace->forceFill(['onboarded_at' => now()])->save();
        }

        return redirect($context->sectionPath($project));
    }

    public function show(Request $request, ProjectContext $context): Response
    {
        $workspace = $context->workspaceFor($request->user());
        $project = $context->projectFor($request->user());
        $source = $context->currentSource($project);
        $domain = $project->domains()->latest()->first();
        $progress = $this->progress($workspace, $project, $source);
        $hasStartedSetup = $this->hasStartedSetup($context, $workspace, $project);

        return Inertia::render('Onboarding', [
            'workspace' => [
                'name' => $workspace->name,
                'onboarded_at' => $workspace->onboarded_at?->toIso8601String(),
                'setup_started_at' => $workspace->setup_started_at?->toIso8601String(),
            ],
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'path' => $context->sectionPath($project),
                'setup_path' => $context->sectionPath($project, 'setup'),
                'send_path' => $context->sectionPath($project, 'send'),
                'is_complete' => $this->isComplete($context, $workspace, $project),
                'has_started_setup' => $hasStartedSetup,
                'resume_path' => $context->sectionPath($project, 'setup'),
                'credential_mode' => $this->credentialMode($source),
                'next_step' => collect($progress)->firstWhere('complete', false) ?? Arr::last($progress),
            ],
            'source' => $source ? [
                'name' => $source->name,
                'environment' => $source->environment,
                'provider' => $source->provider->value,
                'ses_region' => $source->ses_region,
                'ses_configuration_set' => $source->ses_configuration_set,
                'cloudflare_account_id' => $source->cloudflare_account_id,
                'default_from_name' => $source->default_from_name,
                'default_from_email' => $source->default_from_email,
                'has_aws_credentials' => filled($source->aws_access_key_id) && filled($source->aws_secret_access_key),
                'has_aws_session_token' => filled($source->aws_session_token),
                'has_cloudflare_credentials' => filled($source->cloudflare_api_token),
                'webhook_url' => $source->provider === SourceProvider::Cloudflare
                    ? null
                    : route('webhooks.ses', $source->webhook_token),
            ] : null,
            'domain' => $domain ? [
                'domain' => $domain->domain,
                'status' => $domain->status,
                'dns_records' => $domain->dns_records ?? [],
            ] : null,
            'progress' => $progress,
            'install' => [
                'compose' => 'docker compose up -d',
                'migrate' => 'docker compose exec app php artisan migrate --force',
                'worker' => 'docker compose exec app php artisan queue:work --queue=default,webhooks',
            ],
        ]);
    }

    /**
     * Live credential probe used by the onboarding wizard and source settings
     * so bad credentials are rejected with a specific reason at entry time,
     * not discovered at send time. Blank credential fields fall back to the
     * currently saved values so already-configured sources can be re-tested.
     */
    public function validateCredentials(Request $request, ProjectContext $context): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(SourceProvider::class)],
            'ses_region' => ['nullable', 'string', 'max:50'],
            'aws_access_key_id' => ['nullable', 'string', 'max:255'],
            'aws_secret_access_key' => ['nullable', 'string', 'max:255'],
            'aws_session_token' => ['nullable', 'string'],
            'cloudflare_api_token' => ['nullable', 'string', 'max:255'],
        ]);

        $current = $context->currentSource($context->projectFor($request->user()));

        $probe = $current->replicate(['webhook_token']);
        $probe->provider = SourceProvider::from($validated['provider']);
        $probe->ses_region = $validated['ses_region'] ?? $current->ses_region ?? 'us-east-1';

        foreach (['aws_access_key_id', 'aws_secret_access_key', 'aws_session_token', 'cloudflare_api_token'] as $credential) {
            if (filled($validated[$credential] ?? null)) {
                $probe->{$credential} = $validated[$credential];
            }
        }

        $result = $this->providers->forSource($probe)->validateCredentials($probe);

        return response()->json($result);
    }

    public function store(StoreOnboardingRequest $request, ProjectContext $context): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $project = $context->projectFor($request->user());
        $source = $context->currentSource($project);
        $validated = $request->validated();
        $workspaceName = filled($validated['workspace_name'] ?? null) ? $validated['workspace_name'] : $workspace->name;
        $projectName = filled($validated['project_name'] ?? null) ? $validated['project_name'] : $project->name;
        $projectSlug = filled($validated['project_slug'] ?? null)
            ? $validated['project_slug']
            : (filled($validated['project_name'] ?? null) ? Str::slug($validated['project_name']) : $project->slug);

        if (
            $projectSlug !== $project->slug
            && $workspace->projects()->where('slug', $projectSlug)->exists()
        ) {
            return back()->withErrors([
                'project_slug' => 'A project with this slug already exists in this workspace.',
            ])->withInput();
        }

        $workspace->update([
            'name' => $workspaceName,
            'setup_started_at' => $workspace->setup_started_at ?? now(),
        ]);

        $project->update([
            'name' => $projectName,
            'slug' => $projectSlug,
            'default_environment' => $validated['environment'],
        ]);

        $sourcePayload = Arr::only($validated, [
            'ses_region',
            'ses_configuration_set',
            'default_from_name',
            'default_from_email',
        ]);

        $sourcePayload['provider'] = ($validated['credential_mode'] ?? null) === 'cloudflare_token'
            ? SourceProvider::Cloudflare
            : SourceProvider::Ses;

        if (($validated['credential_mode'] ?? null) === 'cloudflare_token') {
            $sourcePayload = [
                ...$sourcePayload,
                ...Arr::only($validated, ['cloudflare_account_id', 'cloudflare_api_token']),
            ];
            unset($sourcePayload['ses_region']);
        }

        if (($validated['credential_mode'] ?? null) === 'aws_keys') {
            $sourcePayload = [
                ...$sourcePayload,
                ...Arr::only($validated, [
                    'aws_access_key_id',
                    'aws_secret_access_key',
                    'aws_session_token',
                ]),
            ];
        }

        if (($validated['credential_mode'] ?? null) === 'instance_role') {
            $sourcePayload = [
                ...$sourcePayload,
                'aws_access_key_id' => null,
                'aws_secret_access_key' => null,
                'aws_session_token' => null,
            ];
        }

        if (($validated['credential_mode'] ?? null) !== 'instance_role' && blank($sourcePayload['aws_access_key_id'] ?? null)) {
            unset($sourcePayload['aws_access_key_id']);
        }

        if (($validated['credential_mode'] ?? null) !== 'instance_role' && blank($sourcePayload['aws_secret_access_key'] ?? null)) {
            unset($sourcePayload['aws_secret_access_key']);
        }

        if (($validated['credential_mode'] ?? null) !== 'instance_role' && blank($sourcePayload['aws_session_token'] ?? null)) {
            unset($sourcePayload['aws_session_token']);
        }

        $source->update([
            ...$sourcePayload,
            'name' => $validated['source_name'],
            'environment' => $validated['environment'],
        ]);

        $this->applyValidatedCredentialMeta($source->fresh() ?? $source);

        $newApiKey = null;

        $onboardingWarning = null;

        if (filled($validated['sending_domain'] ?? null)) {
            $domainName = Str::lower(trim((string) $validated['sending_domain']));
            $source = $source->fresh() ?? $source;
            $provider = $this->providers->forSource($source);

            if (! $provider->supportsIdentityCreation() || $provider->hasSendingCredentials($source)) {
                try {
                    $dnsRecords = $provider->dnsRecordsForDomain($source, $domainName);
                } catch (DomainOnboardingException $exception) {
                    $dnsRecords = $exception->fallbackRecords;
                    $onboardingWarning = $exception->getMessage();
                } catch (RequestException|RuntimeException $exception) {
                    return back()->withErrors(['sending_domain' => $exception->getMessage()])->withInput();
                }
            } else {
                $dnsRecords = [];
            }

            $domain = $project->domains()->updateOrCreate(
                ['domain' => $domainName],
                [
                    'status' => 'pending',
                    'dns_records' => $dnsRecords,
                    'verified_at' => null,
                ],
            );

            $source->forceFill(['domain_id' => $domain->id])->save();

            RecheckPendingDomains::dispatch()->delay(now()->addMinute());
        }

        if ($request->boolean('create_api_key')) {
            $issued = ApiKey::issue($project, ($validated['api_key_name'] ?? null) ?: 'Production key', $source);
            $newApiKey = $issued['plain_text'];
        }

        if (filled($validated['webhook_url'] ?? null)) {
            WebhookEndpoint::issue($project, $validated['webhook_url'], ['delivery', 'bounce', 'complaint', 'open', 'click', 'suppress']);
        }

        session(['current_project_slug' => $projectSlug]);

        $project = $project->fresh() ?? $project;
        $workspace = $workspace->fresh() ?? $workspace;

        if ($this->isComplete($context, $workspace, $project) && blank($workspace->onboarded_at)) {
            $workspace->forceFill(['onboarded_at' => now()])->save();
        }

        return redirect($context->sectionPath($project, 'setup'))
            ->with('newApiKey', $newApiKey)
            ->with('toast', $onboardingWarning !== null
                ? ['type' => 'error', 'message' => $onboardingWarning]
                : ['type' => 'success', 'message' => 'Setup saved. DNS verifies automatically; send one real test email when the domain turns green.']);
    }

    private function isComplete(ProjectContext $context, Workspace $workspace, Project $project): bool
    {
        $source = $context->currentSource($project);

        return $this->sourceConfigured($source)
            && $project->domains()->where('status', 'verified')->exists()
            && $project->apiKeys()->exists()
            && $project->emails()->whereNotNull('sent_at')->exists();
    }

    private function hasStartedSetup(ProjectContext $context, Workspace $workspace, Project $project): bool
    {
        $source = $context->currentSource($project);

        return filled($workspace->setup_started_at)
            || filled($source->default_from_email)
            || filled($source->aws_access_key_id)
            || $project->domains()->exists()
            || $project->apiKeys()->exists();
    }

    /**
     * @return array<int, array{key: string, label: string, complete: bool}>
     */
    private function progress(Workspace $workspace, Project $project, ?Source $source): array
    {
        return [
            ['key' => 'workspace', 'label' => 'Workspace and project', 'complete' => filled($workspace->name) && filled($project->name)],
            ['key' => 'source', 'label' => ($source?->provider ?? SourceProvider::Ses)->label().' source', 'complete' => $this->sourceConfigured($source)],
            ['key' => 'domain', 'label' => 'Verified sending domain', 'complete' => $project->domains()->where('status', 'verified')->exists()],
            ['key' => 'api-key', 'label' => 'API key', 'complete' => $project->apiKeys()->exists()],
            ['key' => 'test-send', 'label' => 'Test send', 'complete' => $project->emails()->whereNotNull('sent_at')->exists()],
        ];
    }

    private function sourceConfigured(?Source $source): bool
    {
        if (! $source || blank($source->default_from_email)) {
            return false;
        }

        return $this->providers->forSource($source)->hasSendingCredentials($source);
    }

    /**
     * Re-probe freshly saved credentials to auto-fill anything derivable:
     * the Cloudflare account id (from the token's visible zones) and the
     * initial quota, so neither ever needs a manual step. Blockers are not
     * enforced here — the wizard already gated on them, and a transient
     * provider outage must not lose the user's setup.
     */
    private function applyValidatedCredentialMeta(Source $source): void
    {
        if ($source->provider === SourceProvider::Cloudflare && blank($source->cloudflare_api_token)) {
            return;
        }

        if ($source->provider === SourceProvider::Ses && blank($source->aws_access_key_id) && ! app()->environment('production')) {
            return;
        }

        try {
            $result = $this->providers->forSource($source)->validateCredentials($source);
        } catch (\Throwable $exception) {
            report($exception);

            return;
        }

        $changed = false;

        if (blank($source->cloudflare_account_id) && filled($result['meta']['account_id'] ?? null)) {
            $source->cloudflare_account_id = $result['meta']['account_id'];
            $changed = true;
        }

        if (filled($result['meta']['quota'] ?? null)) {
            $source->last_quota = $result['meta']['quota'];
            $source->last_quota_checked_at = now();
            $changed = true;
        }

        if ($changed) {
            $source->save();
        }
    }

    private function credentialMode(?Source $source): string
    {
        if ($source?->provider === SourceProvider::Cloudflare) {
            return filled($source->cloudflare_api_token) ? 'cloudflare_token' : 'configure_later';
        }

        if ($source && filled($source->aws_access_key_id) && filled($source->aws_secret_access_key)) {
            return 'aws_keys';
        }

        if (app()->environment('production')) {
            return 'instance_role';
        }

        return 'configure_later';
    }
}
