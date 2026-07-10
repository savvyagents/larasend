<?php

namespace App\Http\Controllers;

use App\Enums\SourceProvider;
use App\Http\Requests\DashboardSendEmailRequest;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Http\Requests\UpdateSourceRequest;
use App\Jobs\RecheckPendingDomains;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\DnsRecordVerifier;
use App\Services\EmailSendService;
use App\Services\Providers\CloudflareInboundProvisioner;
use App\Services\Providers\DomainOnboardingException;
use App\Services\Providers\EmailProviderFactory;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class DashboardActionController extends Controller
{
    public function __construct(
        private ProjectContext $projectContext,
        private EmailProviderFactory $providers,
    ) {}

    public function storeDomain(StoreDomainRequest $request): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        $source = $this->sourceFor($project);
        $domainName = Str::lower(trim($request->string('domain')->toString()));
        $onboardingWarning = null;

        try {
            $records = $this->providers->forSource($source)->dnsRecordsForDomain($source, $domainName);
        } catch (DomainOnboardingException $exception) {
            $records = $exception->fallbackRecords;
            $onboardingWarning = $exception->getMessage();
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back()->withErrors(['domain' => $exception->getMessage()])->withInput();
        }

        $domain = $project->domains()->updateOrCreate(
            ['domain' => $domainName],
            [
                'status' => 'pending',
                'dns_records' => $records,
                'verified_at' => null,
            ],
        );

        $source->forceFill(['domain_id' => $domain->id])->save();

        RecheckPendingDomains::dispatch()->delay(now()->addMinute());

        if ($onboardingWarning !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $onboardingWarning]);
        } else {
            $message = $this->providers->forSource($source)->supportsIdentityCreation()
                ? 'Domain added. Add the DKIM records before sending production traffic.'
                : 'Domain added and onboarded for Email Sending in Cloudflare. DNS verifies automatically within a few minutes.';

            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return $this->toProjectSection($project, 'identities');
    }

    public function updateSource(UpdateSourceRequest $request): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        $source = $this->sourceFor($project);
        $validated = $request->validated();

        if (blank($validated['aws_access_key_id'] ?? null)) {
            unset($validated['aws_access_key_id']);
        }

        if (blank($validated['aws_secret_access_key'] ?? null)) {
            unset($validated['aws_secret_access_key']);
        }

        if (blank($validated['aws_session_token'] ?? null)) {
            unset($validated['aws_session_token']);
        }

        if (blank($validated['cloudflare_api_token'] ?? null)) {
            unset($validated['cloudflare_api_token']);
        }

        if (($validated['provider'] ?? null) === 'cloudflare'
            && blank($source->cloudflare_api_token)
            && ! isset($validated['cloudflare_api_token'])) {
            return back()->withErrors([
                'cloudflare_api_token' => 'A Cloudflare API token is required.',
            ])->withInput();
        }

        $source->fill($validated);

        $credentialsChanged = $source->isDirty([
            'provider',
            'ses_region',
            'aws_access_key_id',
            'aws_secret_access_key',
            'aws_session_token',
            'cloudflare_api_token',
            'cloudflare_account_id',
        ]);

        $warnings = [];

        if ($credentialsChanged) {
            $provider = $this->providers->forSource($source);
            $result = $provider->validateCredentials($source);

            if (! $result['ok']) {
                $field = $source->provider === SourceProvider::Cloudflare
                    ? 'cloudflare_api_token'
                    : 'aws_access_key_id';
                $message = collect($result['blockers'])->pluck('message')->implode(' ');

                Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

                return back()->withErrors([$field => $message])->withInput();
            }

            if (blank($source->cloudflare_account_id) && filled($result['meta']['account_id'] ?? null)) {
                $source->cloudflare_account_id = $result['meta']['account_id'];
            }

            if (filled($result['meta']['quota'] ?? null)) {
                $source->last_quota = $result['meta']['quota'];
                $source->last_quota_checked_at = now();
            }

            $warnings = collect($result['warnings'])->pluck('message')->all();
        }

        $source->save();
        $project->forceFill(['default_environment' => $source->environment])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $warnings === []
                ? 'Source updated and credentials verified.'
                : 'Source updated. '.implode(' ', $warnings),
        ]);

        return $this->toProjectSection($project, 'identities');
    }

    public function syncSourceQuota(Request $request): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        $source = $this->sourceFor($project);
        $provider = $this->providers->forSource($source);
        $silent = $request->boolean('silent');

        try {
            $quota = $provider->fetchQuota($source);
        } catch (RuntimeException $exception) {
            if (! $silent) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
            }

            return $this->toProjectSection($project, 'setup');
        } catch (Throwable $exception) {
            report($exception);

            if (! $silent) {
                Inertia::flash('toast', ['type' => 'error', 'message' => "Could not sync {$provider->key()->label()} quota: ".$exception->getMessage()]);
            }

            return $this->toProjectSection($project, 'setup');
        }

        $source->forceFill([
            'last_quota' => $quota,
            'last_quota_checked_at' => now(),
        ])->save();

        if (! $silent) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->key()->label()} quota synced."]);
        }

        return $this->toProjectSection($project, 'setup');
    }

    public function storeTemplate(StoreTemplateRequest $request): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_templates');

        $validated = $request->validated();
        $variables = collect(explode(',', (string) ($validated['variables'] ?? '')))
            ->map(fn (string $variable) => trim($variable))
            ->filter()
            ->values()
            ->all();

        $project->templates()->updateOrCreate(
            ['slug' => $validated['slug']],
            [
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'html' => $validated['html'] ?? null,
                'text' => $validated['text'] ?? null,
                'variables' => $variables,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template saved.']);

        return $this->toProjectSection($project, 'templates');
    }

    public function storeApiKey(StoreApiKeyRequest $request): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_api_keys');

        $issued = ApiKey::issue(
            $project,
            $request->string('name')->toString(),
            $this->sourceFor($project),
            $request->validated('scopes') ?: ['send', 'read:activity'],
            $request->date('expires_at'),
        );

        Inertia::flash([
            'toast' => ['type' => 'success', 'message' => 'API key created. Copy it now; it will not be shown again.'],
        ]);

        return $this->toProjectSection($project, 'api-keys')->with('newApiKey', $issued['plain_text']);
    }

    public function destroyApiKey(string|ApiKey $projectOrApiKey, ?ApiKey $apiKey = null): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_api_keys');

        $apiKey = $apiKey ?? $projectOrApiKey;

        abort_unless($apiKey instanceof ApiKey, 404);

        abort_unless($apiKey->project_id === $project->id, 404);

        $apiKey->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'API key deleted.']);

        return $this->toProjectSection($project, 'api-keys');
    }

    public function rotateApiKey(string|ApiKey $projectOrApiKey, ?ApiKey $apiKey = null): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_api_keys');

        $apiKey = $apiKey ?? $projectOrApiKey;

        abort_unless($apiKey instanceof ApiKey, 404);
        abort_unless($apiKey->project_id === $project->id, 404);

        $issued = ApiKey::issue(
            $project,
            $apiKey->name,
            $apiKey->source,
            $apiKey->scopes ?: ['send', 'read:activity'],
            $apiKey->expires_at,
        );

        $apiKey->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'API key rotated. Copy the new key now.']);

        return $this->toProjectSection($project, 'api-keys')->with('newApiKey', $issued['plain_text']);
    }

    public function storeWebhookEndpoint(StoreWebhookEndpointRequest $request): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_webhooks');

        $validated = $request->validated();
        $issued = WebhookEndpoint::issue($project, $validated['url'], $validated['events'], $validated['status']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Webhook endpoint created. Copy the signing secret now; it will not be shown again.']);

        return $this->toProjectSection($project, 'webhooks')->with('newWebhookEndpoint', [
            'id' => $issued['endpoint']->public_id,
            'url' => $issued['endpoint']->url,
            'secret' => $issued['plain_text'],
            'events' => $issued['endpoint']->events,
        ]);
    }

    public function updateWebhookEndpoint(StoreWebhookEndpointRequest $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_webhooks');

        abort_unless($endpoint->project_id === $project->id, 404);

        $endpoint->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Webhook endpoint updated.']);

        return $this->toProjectSection($project, 'webhooks');
    }

    public function sendEmail(DashboardSendEmailRequest $request, EmailSendService $emailSendService): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'send');

        $source = $this->sourceFor($project);

        if (! $this->canSend($project, $source)) {
            return $this->redirectToSendSetup($project);
        }

        $email = $emailSendService->send($project, $source, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Email queued as {$email->public_id}."]);

        return $this->toProjectSection($project, 'activity');
    }

    public function resendEmail(Email $email, EmailSendService $emailSendService): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());

        return $this->resendStoredEmail($project, $email, $emailSendService);
    }

    public function resendProjectEmail(string $projectSlug, Email $email, EmailSendService $emailSendService): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());

        return $this->resendStoredEmail($project, $email, $emailSendService);
    }

    private function resendStoredEmail(Project $project, Email $email, EmailSendService $emailSendService): RedirectResponse
    {
        $this->authorizeWorkspaceCapability($project, 'send');

        abort_unless($email->project_id === $project->id, 404);

        $email->load(['recipients', 'source', 'template']);

        if (! $email->source || ! $this->canSend($project, $email->source)) {
            return $this->redirectToSendSetup($project);
        }

        try {
            $resent = $emailSendService->send($project, $email->source, $this->payloadForRetry($email));
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Could not resend email: '.$exception->getMessage(),
            ]);

            return $this->toProjectSection($project, 'activity');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Email resent as {$resent->public_id}."]);

        return $this->toProjectSection($project, 'activity');
    }

    public function retrySoftBounces(EmailSendService $emailSendService): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'send');

        $retried = 0;

        $project->emails()
            ->with(['recipients', 'events', 'source', 'template'])
            ->where('status', 'bounced')
            ->latest()
            ->limit(100)
            ->get()
            ->filter(fn (Email $email) => $this->isSoftBounce($email))
            ->each(function (Email $email) use ($emailSendService, $project, &$retried): void {
                if (! $email->source || ! $this->canSend($project, $email->source)) {
                    return;
                }

                $emailSendService->send($project, $email->source, $this->payloadForRetry($email));
                $retried++;
            });

        Inertia::flash('toast', ['type' => 'success', 'message' => $retried === 1 ? 'Retried 1 soft bounce.' : "Retried {$retried} soft bounces."]);

        return $this->toProjectSection($project, 'bounces');
    }

    public function checkDomain(Domain $domain, DnsRecordVerifier $dnsVerifier): RedirectResponse
    {
        return $this->recheckDomain($domain, $dnsVerifier);
    }

    public function checkProjectDomain(string $projectSlug, Domain $domain, DnsRecordVerifier $dnsVerifier): RedirectResponse
    {
        return $this->recheckDomain($domain, $dnsVerifier);
    }

    private function recheckDomain(Domain $domain, DnsRecordVerifier $dnsVerifier): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        abort_unless($domain->project_id === $project->id, 404);

        $dnsVerifier->recheck($domain);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'DNS records re-checked.']);

        return $this->toProjectSection($project, 'identities');
    }

    public function enableDomainInbound(Domain $domain, CloudflareInboundProvisioner $provisioner): RedirectResponse
    {
        return $this->provisionInbound($domain, $provisioner);
    }

    public function enableProjectDomainInbound(string $projectSlug, Domain $domain, CloudflareInboundProvisioner $provisioner): RedirectResponse
    {
        return $this->provisionInbound($domain, $provisioner);
    }

    private function provisionInbound(Domain $domain, CloudflareInboundProvisioner $provisioner): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        abort_unless($domain->project_id === $project->id, 404);

        $source = $this->sourceFor($project);

        if ($source->provider !== SourceProvider::Cloudflare) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Inbound email currently requires a Cloudflare source.']);

            return $this->toProjectSection($project, 'identities');
        }

        try {
            $provisioner->enable($source, $domain);
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return $this->toProjectSection($project, 'identities');
        }

        $zoneApex = str($domain->domain)->explode('.')->slice(-2)->implode('.');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Inbound email enabled. Mail sent to any address @{$zoneApex} now appears in the Inbound section.",
        ]);

        return $this->toProjectSection($project, 'identities');
    }

    public function destroyDomain(Domain $domain): RedirectResponse
    {
        return $this->deleteDomain($domain);
    }

    public function destroyProjectDomain(string $projectSlug, Domain $domain): RedirectResponse
    {
        return $this->deleteDomain($domain);
    }

    private function deleteDomain(Domain $domain): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        abort_unless($domain->project_id === $project->id, 404);

        $project->sources()
            ->where('domain_id', $domain->id)
            ->update(['domain_id' => null]);

        $domain->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Domain deleted.']);

        return $this->toProjectSection($project, 'identities');
    }

    private function projectFor(?User $user): Project
    {
        abort_unless($user, 403);

        $projectSlug = request()->route('project');

        return $this->projectContext->projectFor(
            $user,
            is_string($projectSlug) ? $projectSlug : null,
        );
    }

    private function toProjectSection(Project $project, string $section): RedirectResponse
    {
        $projectSlug = request()->route('project');

        if (is_string($projectSlug)) {
            return redirect($this->projectContext->sectionPath($project, $section));
        }

        return to_route($section === 'activity' ? 'activity' : $section);
    }

    private function sourceFor(Project $project): Source
    {
        return $this->projectContext->currentSource($project);
    }

    private function canSend(Project $project, Source $source): bool
    {
        return $project->domains()->whereIn('status', ['verified', 'local'])->exists()
            && $source->last_quota_checked_at?->greaterThan(now()->subHours(6)) === true
            && filled($source->last_quota)
            && $this->providers->forSource($source)->hasSendingCredentials($source);
    }

    private function redirectToSendSetup(Project $project): RedirectResponse
    {
        $label = $this->providers->forSource($this->sourceFor($project))->key()->label();
        $message = "Connect {$label} credentials and verify a sending domain before sending real email.";

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return $this->toProjectSection($project, 'identities')
            ->withErrors(['send' => $message]);
    }

    private function authorizeWorkspaceCapability(Project $project, string $capability): void
    {
        $user = Auth::user();

        abort_unless($user, 403);

        $allowed = match ($capability) {
            'send' => $project->workspace->canSendEmail($user),
            'manage_api_keys' => $project->workspace->canManageApiKeys($user),
            'manage_domains' => $project->workspace->canManageDomains($user),
            'manage_templates' => $project->workspace->canManageTemplates($user),
            'manage_webhooks' => $project->workspace->canManageWebhooks($user),
            default => false,
        };

        abort_unless($allowed, 403);
    }

    private function formatRecipient($recipient): string
    {
        return $recipient->name ? "{$recipient->name} <{$recipient->email}>" : $recipient->email;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForRetry(Email $email): array
    {
        return [
            'from' => trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from_email.'>'),
            'to' => $email->recipients->where('type', 'to')->map(fn ($recipient) => $this->formatRecipient($recipient))->values()->all(),
            'cc' => $email->recipients->where('type', 'cc')->map(fn ($recipient) => $this->formatRecipient($recipient))->values()->all(),
            'bcc' => $email->recipients->where('type', 'bcc')->map(fn ($recipient) => $this->formatRecipient($recipient))->values()->all(),
            'subject' => $email->subject,
            'html' => $email->html,
            'text' => $email->text,
            'headers' => $email->headers ?? [],
            'tags' => array_merge($email->tags ?? [], ['resent_from' => $email->public_id]),
        ];
    }

    private function isSoftBounce(Email $email): bool
    {
        $event = $email->events->where('event_type', 'bounce')->sortByDesc('occurred_at')->first();
        $payload = $event?->payload ?? [];
        $bounce = $payload['bounce'] ?? $payload['Bounce'] ?? [];
        $tags = $email->tags ?? [];
        $type = strtolower((string) ($bounce['bounceType'] ?? $tags['bounce_type'] ?? ''));

        return in_array($type, ['transient', 'soft'], true);
    }
}
