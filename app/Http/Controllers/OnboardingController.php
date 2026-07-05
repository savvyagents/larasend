<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOnboardingRequest;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Source;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\SesV2Client;
use App\Support\ProjectContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OnboardingController extends Controller
{
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
                'ses_region' => $source->ses_region,
                'ses_configuration_set' => $source->ses_configuration_set,
                'default_from_name' => $source->default_from_name,
                'default_from_email' => $source->default_from_email,
                'has_aws_credentials' => filled($source->aws_access_key_id) && filled($source->aws_secret_access_key),
                'has_aws_session_token' => filled($source->aws_session_token),
                'webhook_url' => route('webhooks.ses', $source->webhook_token),
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

    public function store(StoreOnboardingRequest $request, ProjectContext $context, SesV2Client $sesClient): RedirectResponse
    {
        $workspace = $context->workspaceFor($request->user());
        $project = $context->projectFor($request->user());
        $source = $context->currentSource($project);
        $validated = $request->validated();
        $projectSlug = $validated['project_slug'] ?: Str::slug($validated['project_name']);

        if (
            $projectSlug !== $project->slug
            && $workspace->projects()->where('slug', $projectSlug)->exists()
        ) {
            return back()->withErrors([
                'project_slug' => 'A project with this slug already exists in this workspace.',
            ])->withInput();
        }

        $workspace->update([
            'name' => $validated['workspace_name'],
            'setup_started_at' => $workspace->setup_started_at ?? now(),
        ]);

        $project->update([
            'name' => $validated['project_name'],
            'slug' => $projectSlug,
            'default_environment' => $validated['environment'],
        ]);

        $sourcePayload = Arr::only($validated, [
            'ses_region',
            'ses_configuration_set',
            'default_from_name',
            'default_from_email',
        ]);

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

        $newApiKey = null;

        if (filled($validated['sending_domain'] ?? null)) {
            $domainName = Str::lower(trim((string) $validated['sending_domain']));
            $source = $source->fresh() ?? $source;

            if ($this->canCreateSesIdentity($source)) {
                try {
                    $identity = $sesClient->createEmailIdentity($source, $domainName);
                    $dnsRecords = $this->dnsRecordsFor($domainName, $identity['tokens']);
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
            ->with('toast', ['type' => 'success', 'message' => 'Setup saved. Verify DNS, create an API key if needed, then send one real test email.']);
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
            ['key' => 'source', 'label' => 'SES source', 'complete' => $this->sourceConfigured($source)],
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

        return filled($source->aws_access_key_id)
            || app()->environment('production');
    }

    private function credentialMode(?Source $source): string
    {
        if ($source && filled($source->aws_access_key_id) && filled($source->aws_secret_access_key)) {
            return 'aws_keys';
        }

        if (app()->environment('production')) {
            return 'instance_role';
        }

        return 'configure_later';
    }

    private function canCreateSesIdentity(Source $source): bool
    {
        return filled($source->aws_access_key_id)
            || app()->environment('production');
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array<string, string>>
     */
    private function dnsRecordsFor(string $domain, array $tokens): array
    {
        if ($tokens === []) {
            throw new RuntimeException('SES did not return DKIM tokens for this identity. Check the source credentials and try again.');
        }

        return collect($tokens)
            ->map(fn (string $token) => [
                'type' => 'CNAME',
                'name' => "{$token}._domainkey.{$domain}",
                'value' => "{$token}.dkim.amazonses.com",
                'status' => 'pending',
            ])
            ->values()
            ->all();
    }
}
