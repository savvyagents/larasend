<?php

namespace App\Support;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Heartbeats for the two background processes a self-hosted install silently
 * depends on. The queue worker stamps on every poll loop; the scheduler
 * stamps every minute. "Not detected" here is the difference between a
 * mystery ("my email is stuck at queued") and a fix ("run queue:work").
 */
class SystemHealth
{
    public const WORKER_HEARTBEAT_KEY = 'larasend:worker-heartbeat';

    public const SCHEDULER_HEARTBEAT_KEY = 'larasend:scheduler-heartbeat';

    public function recordWorkerHeartbeat(): void
    {
        Cache::put(self::WORKER_HEARTBEAT_KEY, now()->toIso8601String(), 600);
    }

    public function recordSchedulerHeartbeat(): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), 600);
    }

    public function workerHeartbeatAt(): ?CarbonInterface
    {
        return $this->timestamp(self::WORKER_HEARTBEAT_KEY);
    }

    public function schedulerHeartbeatAt(): ?CarbonInterface
    {
        return $this->timestamp(self::SCHEDULER_HEARTBEAT_KEY);
    }

    public function workerIsAlive(): bool
    {
        return $this->workerHeartbeatAt()?->greaterThan(now()->subSeconds(120)) === true;
    }

    public function schedulerIsAlive(): bool
    {
        return $this->schedulerHeartbeatAt()?->greaterThan(now()->subSeconds(180)) === true;
    }

    /**
     * Emails sitting in "queued" long enough that a running worker would
     * already have picked them up.
     */
    public function stuckQueuedEmailCount(Project $project): int
    {
        return $project->emails()
            ->where('status', 'queued')
            ->where('created_at', '<', now()->subMinutes(2))
            ->count();
    }

    private function timestamp(string $key): ?CarbonInterface
    {
        $value = Cache::get($key);

        return is_string($value) && $value !== '' ? Date::parse($value) : null;
    }
}
