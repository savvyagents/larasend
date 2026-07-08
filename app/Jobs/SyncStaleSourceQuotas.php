<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\Providers\EmailProviderFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Keeps sources' provider quota fresh without anyone clicking "Sync quota".
 * Uses the same six-hour freshness window EmailSendService checks before
 * accepting sends.
 */
class SyncStaleSourceQuotas implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(EmailProviderFactory $providers): void
    {
        Source::query()
            ->where(function ($query): void {
                $query->whereNull('last_quota_checked_at')
                    ->orWhere('last_quota_checked_at', '<', now()->subHours(6));
            })
            ->each(function (Source $source) use ($providers): void {
                $provider = $providers->forSource($source);

                if (! $provider->hasSendingCredentials($source)) {
                    return;
                }

                try {
                    $source->forceFill([
                        'last_quota' => $provider->fetchQuota($source),
                        'last_quota_checked_at' => now(),
                    ])->save();
                } catch (Throwable $exception) {
                    // One unreachable provider must not abort the batch.
                    report($exception);
                }
            });
    }
}
