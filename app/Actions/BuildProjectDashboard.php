<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookDelivery;
use Carbon\CarbonInterface;

class BuildProjectDashboard
{
    /**
     * @param  array{worker_alive: bool, worker_last_seen: ?string, scheduler_alive: bool, scheduler_last_seen: ?string, stuck_queued: int}  $system
     * @param  array{sent: int, limit: ?int, rate: ?int, sentLast24Hours: ?int, checkedAt: ?string}  $quota
     * @return array<string, mixed>
     */
    public function execute(
        Project $project,
        ?Source $source,
        User $user,
        string $range,
        CarbonInterface $currentStart,
        CarbonInterface $currentEnd,
        array $system,
        array $quota,
        int $inboxUnread,
        bool $sourceReady,
    ): array {
        $outbound = $project->emails()
            ->whereBetween('created_at', [$currentStart, $currentEnd])
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw("count(case when status = 'failed' then 1 end) as failed")
            ->selectRaw("count(case when status = 'queued' then 1 end) as queued")
            ->selectRaw("count(case when status = 'bounced' then 1 end) as bounced")
            ->selectRaw("count(case when status = 'complained' then 1 end) as complained")
            ->first();

        $activeThreads = $project->threads()
            ->whereNull('archived_at')
            ->where('status', '!=', 'closed')
            ->where(fn ($query) => $query
                ->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now()))
            ->toBase()
            ->selectRaw('count(*) as open')
            ->selectRaw("count(case when status = 'pending' then 1 end) as pending")
            ->selectRaw("count(case when priority = 'urgent' then 1 end) as urgent")
            ->selectRaw('count(case when assigned_to_user_id is null then 1 end) as unassigned')
            ->selectRaw('count(case when assigned_to_user_id = ? then 1 end) as mine', [$user->id])
            ->first();

        $domains = $project->domains()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw("count(case when status in ('verified', 'local') then 1 end) as verified")
            ->selectRaw('count(case when inbound_enabled_at is not null then 1 end) as inbound')
            ->first();

        $apiKeys = $project->apiKeys()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('count(case when expires_at is not null and expires_at <= ? then 1 end) as expiring', [now()->addDays(7)])
            ->first();

        $failingWebhooks = WebhookDelivery::query()
            ->whereIn('webhook_endpoint_id', $project->webhookEndpoints()->select('id'))
            ->where('status', 'fail')
            ->whereBetween('delivered_at', [$currentStart, $currentEnd])
            ->count();

        $summary = [
            'outbound' => [
                'total' => (int) ($outbound->total ?? 0),
                'failed' => (int) ($outbound->failed ?? 0),
                'queued' => (int) ($outbound->queued ?? 0),
                'bounced' => (int) ($outbound->bounced ?? 0),
                'complained' => (int) ($outbound->complained ?? 0),
            ],
            'inbox' => [
                'open' => (int) ($activeThreads->open ?? 0),
                'unread' => $inboxUnread,
                'mine' => (int) ($activeThreads->mine ?? 0),
                'unassigned' => (int) ($activeThreads->unassigned ?? 0),
                'urgent' => (int) ($activeThreads->urgent ?? 0),
                'pending' => (int) ($activeThreads->pending ?? 0),
                'snoozed' => $project->threads()->where('snoozed_until', '>', now())->count(),
            ],
            'configuration' => [
                'provider' => $source?->provider->label() ?? 'Not connected',
                'source_ready' => $sourceReady,
                'domains' => (int) ($domains->total ?? 0),
                'verified_domains' => (int) ($domains->verified ?? 0),
                'inbound_domains' => (int) ($domains->inbound ?? 0),
                'quota' => $quota,
            ],
            'developer' => [
                'active_webhooks' => $project->webhookEndpoints()->where('status', 'active')->count(),
                'failing_webhooks' => $failingWebhooks,
                'api_keys' => (int) ($apiKeys->total ?? 0),
                'expiring_api_keys' => (int) ($apiKeys->expiring ?? 0),
            ],
            'trend' => $this->trend($project, $range, $currentStart, $currentEnd),
        ];

        return [
            ...$summary,
            'attention' => $this->attention($summary, $system),
        ];
    }

    /**
     * @return array<int, array{label: string, sent: int, delivered: int, failed: int}>
     */
    private function trend(Project $project, string $range, CarbonInterface $start, CarbonInterface $end): array
    {
        $bucketCount = $range === '1h' ? 6 : 8;
        $bucketSeconds = max(1, (int) ceil($start->diffInSeconds($end) / $bucketCount));
        $query = $project->emails()->toBase()->selectRaw('count(*) as aggregate_guard');
        $buckets = [];

        foreach (range(0, $bucketCount - 1) as $index) {
            $bucketStart = $start->copy()->addSeconds($bucketSeconds * $index);
            $bucketEnd = $index === $bucketCount - 1
                ? $end->copy()->addSecond()
                : $start->copy()->addSeconds($bucketSeconds * ($index + 1));

            $query
                ->selectRaw("count(case when created_at >= ? and created_at < ? then 1 end) as sent_{$index}", [$bucketStart, $bucketEnd])
                ->selectRaw("count(case when created_at >= ? and created_at < ? and status in ('delivered', 'opened', 'clicked') then 1 end) as delivered_{$index}", [$bucketStart, $bucketEnd])
                ->selectRaw("count(case when created_at >= ? and created_at < ? and status in ('failed', 'bounced', 'complained') then 1 end) as failed_{$index}", [$bucketStart, $bucketEnd]);

            $buckets[] = [
                'label' => $this->bucketLabel($range, $bucketStart),
                'sent_key' => "sent_{$index}",
                'delivered_key' => "delivered_{$index}",
                'failed_key' => "failed_{$index}",
            ];
        }

        $counts = $query->first();

        return collect($buckets)->map(fn (array $bucket): array => [
            'label' => $bucket['label'],
            'sent' => (int) ($counts->{$bucket['sent_key']} ?? 0),
            'delivered' => (int) ($counts->{$bucket['delivered_key']} ?? 0),
            'failed' => (int) ($counts->{$bucket['failed_key']} ?? 0),
        ])->all();
    }

    private function bucketLabel(string $range, CarbonInterface $start): string
    {
        return match ($range) {
            '1h', '24h' => $start->format('g a'),
            '7d' => $start->format('D'),
            default => $start->format('M j'),
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $system
     * @return array<int, array{key: string, label: string, description: string, count: int, section: string, tone: string}>
     */
    private function attention(array $summary, array $system): array
    {
        $items = [
            ['key' => 'failed', 'label' => 'Failed sends', 'description' => 'Messages that need investigation or a retry.', 'count' => $summary['outbound']['failed'], 'section' => 'outbound', 'tone' => 'danger'],
            ['key' => 'urgent', 'label' => 'Urgent conversations', 'description' => 'High-priority inbox work awaiting the team.', 'count' => $summary['inbox']['urgent'], 'section' => 'inbox', 'tone' => 'danger'],
            ['key' => 'unassigned', 'label' => 'Unassigned conversations', 'description' => 'Open conversations without an owner.', 'count' => $summary['inbox']['unassigned'], 'section' => 'inbox', 'tone' => 'warning'],
            ['key' => 'webhooks', 'label' => 'Webhook failures', 'description' => 'Failed webhook deliveries in this period.', 'count' => $summary['developer']['failing_webhooks'], 'section' => 'webhooks', 'tone' => 'warning'],
            ['key' => 'api-keys', 'label' => 'API keys expiring soon', 'description' => 'Keys expiring within the next seven days.', 'count' => $summary['developer']['expiring_api_keys'], 'section' => 'api-keys', 'tone' => 'warning'],
            ['key' => 'queue', 'label' => 'Queued messages are stuck', 'description' => 'The queue worker may need attention.', 'count' => $system['stuck_queued'], 'section' => 'outbound', 'tone' => 'danger'],
        ];

        if (! $system['worker_alive']) {
            $items[] = ['key' => 'worker', 'label' => 'Queue worker not detected', 'description' => 'Outbound delivery can pause until the worker returns.', 'count' => 1, 'section' => 'source', 'tone' => 'danger'];
        }

        if (! $system['scheduler_alive']) {
            $items[] = ['key' => 'scheduler', 'label' => 'Scheduler not detected', 'description' => 'Quota and suppression syncs may be delayed.', 'count' => 1, 'section' => 'source', 'tone' => 'warning'];
        }

        return collect($items)->filter(fn (array $item): bool => $item['count'] > 0)->values()->all();
    }
}
