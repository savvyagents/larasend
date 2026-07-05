<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardSendEmailRequest;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Http\Requests\UpdateSourceRequest;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\DnsRecordVerifier;
use App\Services\EmailSendService;
use App\Services\SesV2Client;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class DashboardActionController extends Controller
{
    public function __construct(private ProjectContext $projectContext) {}

    public function storeDomain(StoreDomainRequest $request, SesV2Client $sesClient): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        $source = $this->sourceFor($project);
        $domainName = Str::lower(trim($request->string('domain')->toString()));

        try {
            $identity = $sesClient->createEmailIdentity($source, $domainName);
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back()->withErrors(['domain' => $exception->getMessage()])->withInput();
        }

        $records = $this->dnsRecordsFor($domainName, $identity['tokens']);

        $domain = $project->domains()->updateOrCreate(
            ['domain' => $domainName],
            [
                'status' => 'pending',
                'dns_records' => $records,
                'verified_at' => null,
            ],
        );

        $source->forceFill(['domain_id' => $domain->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Domain added. Add the DKIM records before sending production traffic.']);

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

        $source->update($validated);
        $project->forceFill(['default_environment' => $source->environment])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'SES source updated.']);

        return $this->toProjectSection($project, 'identities');
    }

    public function syncSourceQuota(Request $request, SesV2Client $sesClient): RedirectResponse
    {
        $project = $this->projectFor(Auth::user());
        $this->authorizeWorkspaceCapability($project, 'manage_domains');

        $source = $this->sourceFor($project);
        $silent = $request->boolean('silent');

        try {
            $account = $sesClient->getAccount($source);
        } catch (RuntimeException $exception) {
            if (! $silent) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
            }

            return $this->toProjectSection($project, 'setup');
        } catch (Throwable $exception) {
            report($exception);

            if (! $silent) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'Could not sync SES quota: '.$exception->getMessage()]);
            }

            return $this->toProjectSection($project, 'setup');
        }

        $source->forceFill([
            'last_quota' => $account['SendQuota'] ?? $account,
            'last_quota_checked_at' => now(),
        ])->save();

        if (! $silent) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'SES quota synced.']);
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

        $records = collect($domain->dns_records ?? [])
            ->map(function (array $record) use ($dnsVerifier) {
                return [
                    ...$record,
                    'status' => $dnsVerifier->matches($record) ? 'ok' : 'pending',
                ];
            })
            ->values()
            ->all();

        $allRecordsPass = collect($records)->every(fn (array $record) => ($record['status'] ?? null) === 'ok');

        $domain->forceFill([
            'status' => $allRecordsPass ? 'verified' : 'pending',
            'dns_records' => $records,
            'verified_at' => $allRecordsPass ? now() : null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'DNS records re-checked.']);

        return $this->toProjectSection($project, 'identities');
    }

    public function destroyDomain(Domain $domain): RedirectResponse
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
            && (
                filled($source->aws_access_key_id)
                || app()->environment('production')
            );
    }

    private function redirectToSendSetup(Project $project): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => 'Connect SES credentials and verify a sending domain before sending real email.',
        ]);

        return $this->toProjectSection($project, 'identities')
            ->withErrors(['send' => 'Connect SES credentials and verify a sending domain before sending real email.']);
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

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array<string, string>>
     */
    private function dnsRecordsFor(string $domain, array $tokens): array
    {
        return collect($tokens)
            ->map(fn (string $token) => [
                'type' => 'CNAME',
                'name' => "{$token}._domainkey.{$domain}",
                'value' => "{$token}.dkim.amazonses.com",
                'status' => 'pending',
            ])
            ->whenEmpty(fn ($records) => $records->push([
                'type' => 'TXT',
                'name' => "_amazonses.{$domain}",
                'value' => Arr::random(['created-by-larasend-local-mode']),
                'status' => 'ok',
            ]))
            ->values()
            ->all();
    }
}
