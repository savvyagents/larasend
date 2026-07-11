<?php

namespace App\Http\Controllers;

use App\Enums\SourceProvider;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Providers\EmailProviderFactory;
use App\Support\ProjectContext;
use App\Support\SystemHealth;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function __construct(
        private EmailProviderFactory $providers,
        private SystemHealth $systemHealth,
    ) {}

    public function __invoke(Request $request, ProjectContext $context): Response|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $projectSlug = $request->route('project');
        $project = $context->projectFor(
            $user,
            is_string($projectSlug) ? $projectSlug : null,
        );
        $source = $context->currentSource($project);

        $section = (string) $request->route('section', 'activity');

        if ($section === 'send' && (! $source || ! $this->canSend($project, $source))) {
            $label = ($source?->provider ?? SourceProvider::Ses)->label();

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => "Connect {$label} credentials and verify a sending domain before sending real email.",
            ]);

            return redirect($context->sectionPath($project, 'identities'));
        }

        $emails = $this->activityEmailsQuery($project, $request, $section)
            ->limit(50)
            ->get();

        $selected = $emails->first();
        $canSend = $source ? $this->canSend($project, $source) : false;

        return Inertia::render('Activity', [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'environment' => $source?->environment ?? $project->default_environment,
                'provider' => ($source?->provider ?? SourceProvider::Ses)->value,
                'provider_label' => ($source?->provider ?? SourceProvider::Ses)->label(),
                'region' => $source?->provider === SourceProvider::Cloudflare ? null : ($source?->ses_region ?? 'us-east-1'),
                'path' => $context->sectionPath($project),
                'exportPath' => $context->exportPath($project),
            ],
            'workspace' => [
                'name' => $project->workspace->name,
                'slug' => $project->workspace->slug,
                'can_manage_members' => $project->workspace->canManageMembers($user),
                'can_manage_api_keys' => $project->workspace->canManageApiKeys($user),
                'can_manage_domains' => $project->workspace->canManageDomains($user),
                'can_send' => $project->workspace->canSendEmail($user),
            ],
            'workspaceMembers' => $this->workspaceMembers($project),
            'projects' => $context->projectsFor($user)->map(fn (Project $workspaceProject): array => [
                'name' => $workspaceProject->name,
                'slug' => $workspaceProject->slug,
                'environment' => $workspaceProject->sources->first()?->environment ?? $workspaceProject->default_environment,
                'provider_label' => ($workspaceProject->sources->first()?->provider ?? SourceProvider::Ses)->label(),
                'region' => $workspaceProject->sources->first()?->provider === SourceProvider::Cloudflare
                    ? null
                    : ($workspaceProject->sources->first()?->ses_region ?? 'us-east-1'),
                'emails_count' => $workspaceProject->emails_count,
                'domains_count' => $workspaceProject->domains_count,
                'is_current' => $workspaceProject->id === $project->id,
                'href' => $context->sectionPath($workspaceProject, 'activity'),
                'can_delete' => $workspaceProject->emails_count === 0 && $workspaceProject->domains_count === 0,
                'delete_block_reason' => $this->deleteBlockReason($workspaceProject),
            ])->values(),
            'archivedProjects' => $context->archivedProjectsFor($user)->map(fn (Project $workspaceProject): array => [
                'name' => $workspaceProject->name,
                'slug' => $workspaceProject->slug,
                'environment' => $workspaceProject->sources->first()?->environment ?? $workspaceProject->default_environment,
                'region' => $workspaceProject->sources->first()?->ses_region ?? 'us-east-1',
                'emails_count' => $workspaceProject->emails_count,
                'domains_count' => $workspaceProject->domains_count,
                'archived_at' => $workspaceProject->archived_at?->diffForHumans(short: true),
            ])->values(),
            'section' => $section,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'range' => $request->string('range', '14d')->toString(),
            ],
            'metrics' => $this->metrics($project, $request->string('range', '14d')->toString()),
            'bounceMetrics' => $this->bounceMetrics($project),
            'bounceQueue' => $this->bounceQueue($project, $request),
            'emails' => $emails->map(fn (Email $email) => $this->serializeEmail($email, detailed: true)),
            'selectedEmail' => $selected ? $this->serializeEmail($selected, detailed: true) : null,
            'source' => $source ? [
                'name' => $source->name,
                'environment' => $source->environment,
                'provider' => $source->provider->value,
                'provider_label' => $source->provider->label(),
                'ses_region' => $source->ses_region,
                'ses_configuration_set' => $source->ses_configuration_set,
                'cloudflare_account_id' => $source->cloudflare_account_id,
                'default_from_name' => $source->default_from_name,
                'default_from_email' => $source->default_from_email,
                'retention_days' => $source->retention_days,
                'has_aws_credentials' => filled($source->aws_access_key_id) && filled($source->aws_secret_access_key),
                'has_aws_session_token' => filled($source->aws_session_token),
                'has_cloudflare_credentials' => filled($source->cloudflare_api_token),
                'uses_instance_role' => $source->provider === SourceProvider::Ses
                    && app()->environment('production')
                    && blank($source->aws_access_key_id)
                    && blank($source->aws_secret_access_key),
                'can_send' => $canSend,
                'capabilities' => $this->sourceCapabilities($source),
            ] : null,
            'domains' => $project->domains()->latest()->get(['id', 'domain', 'status', 'dns_records', 'verified_at', 'inbound_enabled_at']),
            'inboundEmails' => $section === 'inbound'
                ? $project->inboundEmails()
                    ->latest('received_at')
                    ->limit(50)
                    ->get(['public_id', 'from_email', 'from_name', 'to_email', 'subject', 'text', 'html', 'attachments', 'received_at'])
                : [],
            'templates' => $project->templates()->latest()->get(['slug', 'name', 'subject', 'html', 'text', 'variables', 'updated_at']),
            'webhooks' => $this->webhookEndpoints($project),
            'webhookStats' => $this->webhookStats($project),
            'webhookDeliveries' => $this->webhookDeliveries($project),
            'suppressions' => $this->suppressions($project),
            'newWebhookEndpoint' => session('newWebhookEndpoint'),
            'sesWebhookUrl' => $source && $source->provider === SourceProvider::Ses
                ? route('webhooks.ses', $source->webhook_token)
                : null,
            'apiKeys' => $project->apiKeys()->latest()->get(['id', 'name', 'prefix', 'scopes', 'last_used_at', 'last_used_ip', 'last_used_user_agent', 'expires_at', 'created_at']),
            'newApiKey' => session('newApiKey'),
            'inboundError' => session('inboundError'),
            'setup' => $this->setup($project, $source, $context),
            'sidebarCounts' => [
                'activity' => $project->emails()->count(),
                'inbound' => $project->inboundEmails()->count(),
                'sent' => $project->emails()->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])->count(),
                'bounces' => $project->emails()->where('status', 'bounced')->count(),
                'complaints' => $project->emails()->where('status', 'complained')->count(),
                'suppressions' => $project->suppressions()->count(),
            ],
            'inboxUnread' => $project->threads()->whereNull('archived_at')->whereNull('read_at')->count(),
            'quota' => $this->quota($project, $source),
            'system' => [
                'worker_alive' => $this->systemHealth->workerIsAlive(),
                'worker_last_seen' => $this->systemHealth->workerHeartbeatAt()?->diffForHumans(),
                'scheduler_alive' => $this->systemHealth->schedulerIsAlive(),
                'scheduler_last_seen' => $this->systemHealth->schedulerHeartbeatAt()?->diffForHumans(),
                'stuck_queued' => $this->systemHealth->stuckQueuedEmailCount($project),
            ],
        ]);
    }

    /**
     * @return array{steps: array<int, array<string, mixed>>, next_step: array<string, mixed>|null, webhook_url: string|null}
     */
    private function setup(Project $project, ?Source $source, ProjectContext $context): array
    {
        $isCloudflare = $source?->provider === SourceProvider::Cloudflare;
        $domain = $project->domains()->latest()->first();
        $hasSendingCredentials = $source && $this->hasSendingCredentials($source);
        $canSend = $source && $this->canSend($project, $source);
        $webhookUrl = $source && ! $isCloudflare ? route('webhooks.ses', $source->webhook_token) : null;
        $hasSesEvent = $this->hasSesEvent($project);
        $steps = [
            $isCloudflare
                ? [
                    'key' => 'source',
                    'label' => 'Configure Cloudflare source',
                    'description' => 'Save the default sender, your Cloudflare account ID, and an API token with Email Sending Edit, Zone Read, and DNS Edit permissions.',
                    'complete' => (bool) ($source?->default_from_email && $hasSendingCredentials),
                    'href' => $context->sectionPath($project, 'identities'),
                ]
                : [
                    'key' => 'source',
                    'label' => 'Configure SES source',
                    'description' => 'Save the AWS region, default sender, and either IAM access keys or an attached production instance role.',
                    'complete' => (bool) ($source?->default_from_email && $hasSendingCredentials),
                    'href' => $context->sectionPath($project, 'identities'),
                ],
            $isCloudflare
                ? [
                    'key' => 'domain',
                    'label' => 'Verify sending domain',
                    'description' => 'Add the sending domain and Larasend onboards it for Email Sending in Cloudflare and publishes the DNS records automatically, then re-check DNS. Requires the token to have Zone Read and DNS Edit.',
                    'complete' => (bool) ($domain && $domain->status === 'verified'),
                    'href' => $context->sectionPath($project, 'identities'),
                ]
                : [
                    'key' => 'domain',
                    'label' => 'Verify sending domain',
                    'description' => 'Create the SES identity and publish the DKIM records shown in Larasend.',
                    'complete' => (bool) ($domain && $domain->status === 'verified'),
                    'href' => $context->sectionPath($project, 'identities'),
                ],
            ...$isCloudflare ? [] : [[
                'key' => 'webhook',
                'label' => 'Connect SES events',
                'description' => $webhookUrl
                    ? 'Point SES/SNS events at the source webhook URL. This is complete after Larasend receives the first provider event.'
                    : 'Save a source first, then point SES/SNS delivery, bounce, complaint, open, and click events at the generated URL.',
                'complete' => $hasSesEvent,
                'href' => $context->sectionPath($project, 'webhooks'),
                'status' => $hasSesEvent ? 'Events received' : ($webhookUrl ? 'Webhook URL ready' : 'Source missing'),
            ]],
            [
                'key' => 'api-key',
                'label' => 'Create an API key',
                'description' => 'Issue a Larasend API key for your Laravel application or HTTP integration.',
                'complete' => $project->apiKeys()->exists(),
                'href' => $context->sectionPath($project, 'api-keys'),
            ],
            [
                'key' => 'test-send',
                'label' => 'Send a test email',
                'description' => 'Send a real transactional email and confirm it appears in Activity with stored preview and timeline.',
                'complete' => (bool) ($canSend && $project->emails()->whereNotNull('sent_at')->exists()),
                'href' => $context->sectionPath($project, 'send'),
            ],
        ];

        return [
            'webhook_url' => $webhookUrl,
            'next_step' => collect($steps)->firstWhere('complete', false),
            'steps' => $steps,
        ];
    }

    /**
     * @return array{identity_creation: bool, inbound_event_webhooks: bool, open_click_tracking: bool, suppression_sync: bool}
     */
    private function sourceCapabilities(Source $source): array
    {
        $provider = $this->providers->forSource($source);

        return [
            'identity_creation' => $provider->supportsIdentityCreation(),
            'inbound_event_webhooks' => $provider->supportsInboundEventWebhooks(),
            'open_click_tracking' => $provider->supportsOpenClickTracking(),
            'suppression_sync' => $provider->supportsSuppressionSync(),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, email: string, role: string, is_owner: bool}>
     */
    private function workspaceMembers(Project $project): Collection
    {
        return $project->workspace->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'is_owner' => $project->workspace->owner_id === $user->id,
            ])
            ->values();
    }

    private function canSend(Project $project, Source $source): bool
    {
        return $this->hasSendingCredentials($source)
            && $this->hasVerifiedDomain($project)
            && ! $this->hasUnsafeComplaintRate($project);
    }

    private function hasSendingCredentials(Source $source): bool
    {
        return $this->providers->forSource($source)->hasSendingCredentials($source);
    }

    private function hasVerifiedDomain(Project $project): bool
    {
        return $project->domains()->whereIn('status', ['verified', 'local'])->exists();
    }

    private function hasSesEvent(Project $project): bool
    {
        return $project->emails()
            ->whereHas('events', function ($query): void {
                $query->whereIn('event_type', ['delivery', 'bounce', 'complaint', 'open', 'click', 'suppress']);
            })
            ->exists();
    }

    private function hasUnsafeComplaintRate(Project $project): bool
    {
        $since = now()->subDays(30);
        $total = $project->emails()->where('created_at', '>=', $since)->count();

        if ($total < 100) {
            return false;
        }

        $complaints = $project->emails()
            ->where('created_at', '>=', $since)
            ->where('status', 'complained')
            ->count();

        return ($complaints / $total) >= 0.001;
    }

    private function deleteBlockReason(Project $project): ?string
    {
        if ($project->emails_count > 0) {
            return 'has '.number_format($project->emails_count).' sends, archive instead';
        }

        if ($project->domains_count > 0) {
            return 'has '.number_format($project->domains_count).' domains, archive instead';
        }

        return null;
    }

    private function activityEmailsQuery(Project $project, Request $request, string $section): HasMany
    {
        $query = $project->emails()
            ->with(['recipients', 'events', 'attachments', 'source', 'template'])
            ->latest();

        match ($section) {
            'sent' => $query->whereIn('status', ['sent', 'delivered', 'opened', 'clicked']),
            'bounces' => $query->where('status', 'bounced'),
            'complaints' => $query->where('status', 'complained'),
            default => null,
        };

        $range = $request->string('range', '14d')->toString();
        $since = match ($range) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDays(14),
        };

        $query->where('created_at', '>=', $since);

        $search = trim($request->string('q')->toString());

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereLike('public_id', "%{$search}%")
                    ->orWhereLike('ses_message_id', "%{$search}%")
                    ->orWhereLike('subject', "%{$search}%")
                    ->orWhereLike('from_email', "%{$search}%")
                    ->orWhereHas('recipients', function ($query) use ($search) {
                        $query->whereLike('email', "%{$search}%")
                            ->orWhereLike('name', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function bounceMetrics(Project $project): array
    {
        $since = now()->subDays(30);
        $total = max($project->emails()->where('created_at', '>=', $since)->count(), 1);
        $bounces = $project->emails()->where('status', 'bounced')->where('created_at', '>=', $since)->with('events')->get();
        $hard = $bounces->filter(fn (Email $email) => $this->bounceTypeFor($email) === 'Hard')->count();
        $soft = $bounces->filter(fn (Email $email) => $this->bounceTypeFor($email) === 'Soft')->count();

        return [
            ['label' => 'Bounce rate (30d)', 'value' => number_format(($bounces->count() / $total) * 100, 2).'%', 'meta' => number_format($total).' sends', 'tone' => $bounces->count() > 0 ? 'danger' : 'success'],
            ['label' => 'Total bounces', 'value' => number_format($bounces->count()), 'meta' => 'last 30 days', 'tone' => 'neutral'],
            ['label' => 'Hard bounces', 'value' => number_format($hard), 'meta' => number_format(($hard / max($bounces->count(), 1)) * 100, 1).'% of total', 'tone' => 'neutral'],
            ['label' => 'Soft bounces', 'value' => number_format($soft), 'meta' => number_format(($soft / max($bounces->count(), 1)) * 100, 1).'% of total', 'tone' => 'neutral'],
            ['label' => 'AWS reputation', 'value' => $bounces->count() > 25 ? 'Review' : 'Healthy', 'meta' => $bounces->count() > 25 ? 'investigate spike' : 'no action needed', 'tone' => $bounces->count() > 25 ? 'danger' : 'success'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bounceQueue(Project $project, Request $request): array
    {
        return $this->activityEmailsQuery($project, $request, 'bounces')
            ->with(['recipients', 'events', 'template'])
            ->limit(50)
            ->get()
            ->map(function (Email $email): array {
                $bounce = $this->bounceDetailsFor($email);
                $recipient = $email->recipients->firstWhere('type', 'to');

                return [
                    'id' => $email->public_id,
                    'type' => $bounce['type'],
                    'recipient' => $recipient?->email,
                    'reason' => $bounce['reason'],
                    'smtp' => $bounce['smtp'],
                    'mx' => $bounce['mx'],
                    'template' => $email->template?->slug,
                    'attempts' => $bounce['attempts'],
                    'when' => $email->created_at->diffForHumans(short: true),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function webhookEndpoints(Project $project): array
    {
        return $project->webhookEndpoints()
            ->with('latestDelivery')
            ->withCount('deliveries')
            ->withCount(['deliveries as successful_deliveries_count' => fn ($query) => $query->where('status', 'ok')])
            ->latest('id')
            ->get()
            ->map(fn (WebhookEndpoint $endpoint): array => [
                'id' => $endpoint->public_id,
                'url' => $endpoint->url,
                'events' => $endpoint->events ?? [],
                'status' => $this->webhookStatusFor($endpoint),
                'configured_status' => $endpoint->status,
                'secret_prefix' => $endpoint->secret_prefix,
                'success_rate' => $endpoint->deliveries_count > 0
                    ? number_format(($endpoint->successful_deliveries_count / $endpoint->deliveries_count) * 100, 2).'%'
                    : '—',
                'last_delivered_at' => $endpoint->last_delivered_at?->diffForHumans(short: true) ?? 'Never',
                'created_at' => $endpoint->created_at->toDateString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function webhookStats(Project $project): array
    {
        $endpointIds = $project->webhookEndpoints()->pluck('id');
        $endpoints = $project->webhookEndpoints()->with('latestDelivery')->get();
        $deliveries = WebhookDelivery::query()->whereIn('webhook_endpoint_id', $endpointIds);
        $total = (clone $deliveries)->count();
        $success = (clone $deliveries)->where('status', 'ok')->count();
        $failing = $endpoints->filter(fn (WebhookEndpoint $endpoint) => $this->webhookStatusFor($endpoint) === 'failing')->count();

        return [
            ['label' => 'Endpoints', 'value' => number_format($endpoints->count()), 'meta' => 'configured', 'tone' => 'neutral'],
            ['label' => 'Deliveries (30d)', 'value' => number_format($total), 'meta' => 'all endpoints', 'tone' => 'neutral'],
            ['label' => 'Success rate', 'value' => $total > 0 ? number_format(($success / $total) * 100, 2).'%' : '—', 'meta' => $total > 0 ? number_format($success).' successful' : 'no deliveries', 'tone' => 'success'],
            ['label' => 'Failing', 'value' => number_format($failing), 'meta' => $failing > 0 ? 'requires attention' : 'none', 'tone' => $failing > 0 ? 'danger' : 'success'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suppressions(Project $project): array
    {
        return $project->suppressions()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Suppression $suppression): array => [
                'id' => $suppression->id,
                'email' => $suppression->email,
                'reason' => $suppression->reason,
                'source' => $suppression->event_type,
                'added' => $suppression->created_at->diffForHumans(short: true),
                'expires' => $suppression->expires_at?->toDateString() ?? 'Never',
            ])
            ->all();
    }

    /**
     * @return array{sent: int, limit: int|null, rate: float|null, checkedAt: string|null}
     */
    private function quota(Project $project, ?Source $source): array
    {
        $lastQuota = $source?->last_quota ?? [];

        return [
            'sent' => $project->emails()->where('created_at', '>=', now()->subDays(30))->count(),
            'limit' => $this->quotaValue($lastQuota, ['Max24HourSend', 'max24HourSend', 'max_24_hour_send']),
            'rate' => $this->quotaValue($lastQuota, ['MaxSendRate', 'maxSendRate', 'max_send_rate']),
            'sentLast24Hours' => $this->quotaValue($lastQuota, ['SentLast24Hours', 'sentLast24Hours', 'sent_last_24_hours']),
            'checkedAt' => $source?->last_quota_checked_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $quota
     * @param  array<int, string>  $keys
     */
    private function quotaValue(array $quota, array $keys): int|float|null
    {
        foreach ($keys as $key) {
            if (isset($quota[$key]) && is_numeric($quota[$key])) {
                return $quota[$key] + 0;
            }
        }

        return null;
    }

    private function webhookStatusFor(WebhookEndpoint $endpoint): string
    {
        if ($endpoint->status === 'paused') {
            return 'paused';
        }

        return $endpoint->latestDelivery?->status === 'fail' ? 'failing' : 'healthy';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function webhookDeliveries(Project $project): array
    {
        $endpointIds = $project->webhookEndpoints()->pluck('id');

        return WebhookDelivery::query()
            ->with('endpoint')
            ->whereIn('webhook_endpoint_id', $endpointIds)
            ->latest('delivered_at')
            ->limit(50)
            ->get()
            ->map(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->public_id,
                'status' => $delivery->status,
                'event' => $delivery->event_type,
                'endpoint' => $delivery->endpoint?->public_id,
                'http' => $delivery->http_status,
                'latency' => $delivery->latency_ms,
                'when' => $delivery->delivered_at?->diffForHumans(short: true) ?? $delivery->created_at->diffForHumans(short: true),
            ])
            ->all();
    }

    /**
     * @return array{type: string, reason: string, smtp: string, mx: string, attempts: int}
     */
    private function bounceDetailsFor(Email $email): array
    {
        $event = $email->events->where('event_type', 'bounce')->sortByDesc('occurred_at')->first();
        $payload = $event?->payload ?? [];
        $bounce = $payload['bounce'] ?? $payload['Bounce'] ?? [];
        $recipient = $bounce['bouncedRecipients'][0] ?? [];
        $diagnostic = (string) ($recipient['diagnosticCode'] ?? '');
        $status = (string) ($recipient['status'] ?? '');
        $tags = $email->tags ?? [];

        return [
            'type' => $this->bounceTypeFor($email),
            'reason' => (string) ($recipient['diagnosticCode'] ?? $bounce['bounceSubType'] ?? 'Delivery failed'),
            'smtp' => trim((string) ($recipient['status'] ?? $recipient['action'] ?? '550 5.1.1')),
            'mx' => $this->mxFor((string) ($recipient['emailAddress'] ?? $email->recipients->firstWhere('type', 'to')?->email)),
            'attempts' => (int) ($tags['attempts'] ?? (str_contains($status.$diagnostic, '4.') ? 2 : 1)),
        ];
    }

    private function bounceTypeFor(Email $email): string
    {
        $event = $email->events->where('event_type', 'bounce')->sortByDesc('occurred_at')->first();
        $payload = $event?->payload ?? [];
        $bounce = $payload['bounce'] ?? $payload['Bounce'] ?? [];
        $tags = $email->tags ?? [];
        $type = strtolower((string) ($bounce['bounceType'] ?? $tags['bounce_type'] ?? ''));

        return in_array($type, ['transient', 'soft'], true) ? 'Soft' : 'Hard';
    }

    private function mxFor(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return 'unknown';
        }

        return Str::after($email, '@');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function metrics(Project $project, string $range): array
    {
        [$currentStart, $currentEnd, $priorStart, $priorEnd] = $this->metricPeriods($range);
        $current = $this->metricCounts($project, $currentStart, $currentEnd);
        $prior = $this->metricCounts($project, $priorStart, $priorEnd);
        $currentTotal = max($current['sent'], 1);
        $priorTotal = max($prior['sent'], 1);

        return [
            $this->countMetric('Sent', $current['sent'], $prior['sent']),
            $this->rateMetric('Delivery rate', $current['delivered'] / $currentTotal, $prior['delivered'] / $priorTotal),
            $this->rateMetric('Open rate', $current['opened'] / $currentTotal, $prior['opened'] / $priorTotal),
            $this->rateMetric('Click rate', $current['clicked'] / $currentTotal, $prior['clicked'] / $priorTotal),
            $this->rateMetric('Bounce rate', $current['bounced'] / $currentTotal, $prior['bounced'] / $priorTotal, lowerIsBetter: true, decimals: 2),
            $this->countMetric('Complaints', $current['complained'], $prior['complained'], lowerIsBetter: true),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: CarbonInterface, 3: CarbonInterface}
     */
    private function metricPeriods(string $range): array
    {
        $end = now();
        $start = match ($range) {
            '1h' => $end->copy()->subHour(),
            '24h' => $end->copy()->subDay(),
            '7d' => $end->copy()->subDays(7),
            '30d' => $end->copy()->subDays(30),
            default => $end->copy()->subDays(14),
        };
        $seconds = $start->diffInSeconds($end);
        $priorEnd = $start->copy();
        $priorStart = $priorEnd->copy()->subSeconds($seconds);

        return [$start, $end, $priorStart, $priorEnd];
    }

    /**
     * @return array{sent: int, delivered: int, opened: int, clicked: int, bounced: int, complained: int}
     */
    private function metricCounts(Project $project, CarbonInterface $start, CarbonInterface $end): array
    {
        $baseQuery = $project->emails()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end);

        return [
            'sent' => (clone $baseQuery)->count(),
            'delivered' => (clone $baseQuery)->whereIn('status', ['delivered', 'opened', 'clicked'])->count(),
            'opened' => (clone $baseQuery)->whereIn('status', ['opened', 'clicked'])->count(),
            'clicked' => (clone $baseQuery)->where('status', 'clicked')->count(),
            'bounced' => (clone $baseQuery)->where('status', 'bounced')->count(),
            'complained' => (clone $baseQuery)->where('status', 'complained')->count(),
        ];
    }

    /**
     * @return array{label: string, value: string, delta: string|null, trend: string, tone: string, spark: array<int, int>}
     */
    private function countMetric(string $label, int $current, int $prior, bool $lowerIsBetter = false): array
    {
        $delta = null;
        $difference = $current - $prior;

        if ($prior === 0 && $current > 0) {
            $delta = 'new';
        } elseif ($prior > 0 && $difference !== 0) {
            $delta = ($difference > 0 ? '+' : '').number_format(($difference / $prior) * 100, 0).'%';
        }

        return [
            'label' => $label,
            'value' => number_format($current),
            'delta' => $delta,
            'trend' => $this->trend($difference),
            'tone' => $this->deltaTone($difference, $lowerIsBetter),
            'spark' => [],
        ];
    }

    /**
     * @return array{label: string, value: string, delta: string|null, trend: string, tone: string, spark: array<int, int>}
     */
    private function rateMetric(string $label, float $current, float $prior, bool $lowerIsBetter = false, int $decimals = 1): array
    {
        $difference = ($current - $prior) * 100;
        $delta = abs($difference) >= 0.05
            ? ($difference > 0 ? '+' : '').number_format($difference, 1).' pp'
            : null;

        return [
            'label' => $label,
            'value' => number_format($current * 100, $decimals).'%',
            'delta' => $delta,
            'trend' => $this->trend($difference),
            'tone' => $this->deltaTone($difference, $lowerIsBetter),
            'spark' => [],
        ];
    }

    private function trend(float|int $difference): string
    {
        return $difference > 0 ? 'up' : ($difference < 0 ? 'down' : 'neutral');
    }

    private function deltaTone(float|int $difference, bool $lowerIsBetter): string
    {
        if ($difference == 0) {
            return 'neutral';
        }

        return ($difference < 0) === $lowerIsBetter ? 'good' : 'bad';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEmail(Email $email, bool $detailed = false): array
    {
        $toRecipients = $email->recipients->where('type', 'to')->values();
        $ccRecipients = $email->recipients->where('type', 'cc')->values();
        $bccRecipients = $email->recipients->where('type', 'bcc')->values();
        $primaryRecipient = $toRecipients->first();
        $recipientCount = $toRecipients->count();
        $events = $email->events->sortBy('occurred_at')->values();
        $data = [
            'id' => $email->public_id,
            'recipient' => $this->recipientSummary($toRecipients),
            'recipientEmail' => $primaryRecipient?->email,
            'recipientEmails' => $toRecipients->pluck('email')->join(', '),
            'recipientCount' => $recipientCount,
            'subject' => $email->subject,
            'template' => $email->template?->slug,
            'status' => $email->status,
            'opens' => $email->events->where('event_type', 'open')->count(),
            'clicks' => $email->events->where('event_type', 'click')->count(),
            'time' => $email->created_at->diffForHumans(short: true),
            'createdAt' => $email->created_at->toIso8601String(),
        ];

        if ($detailed) {
            $data += [
                'from' => trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from_email.'>'),
                'to' => $this->formatRecipients($toRecipients),
                'cc' => $this->formatRecipients($ccRecipients),
                'bcc' => $this->formatRecipients($bccRecipients),
                'html' => $email->html,
                'text' => $email->text,
                'headers' => $email->headers,
                'sesMessageId' => $email->ses_message_id,
                'mimeSize' => $email->mime_size,
                'mimeUrl' => route('emails.mime', $email),
                'previewUrl' => route('emails.preview', $email),
                'events' => $events->map(fn ($event) => [
                    'type' => $event->event_type,
                    'recipient' => $event->recipient,
                    'occurredAt' => $event->occurred_at->diffForHumans(short: true),
                ]),
            ];
        }

        return $data;
    }

    private function recipientSummary(Collection $recipients): ?string
    {
        if ($recipients->isEmpty()) {
            return null;
        }

        $visibleRecipients = $recipients
            ->take(2)
            ->pluck('email')
            ->join(', ');
        $remaining = $recipients->count() - 2;

        if ($remaining > 0) {
            return "{$visibleRecipients} + {$remaining} more";
        }

        return $visibleRecipients;
    }

    private function formatRecipients(Collection $recipients): string
    {
        return $recipients
            ->map(fn ($recipient): string => trim(($recipient->name ? $recipient->name.' ' : '').'<'.$recipient->email.'>'))
            ->join(', ');
    }
}
