<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\DnsRecordVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RecheckPendingDomains implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(DnsRecordVerifier $dnsVerifier): void
    {
        Domain::query()
            ->where('status', 'pending')
            ->each(function (Domain $domain) use ($dnsVerifier): void {
                try {
                    $dnsVerifier->recheck($domain);
                } catch (Throwable $exception) {
                    // One slow or failing DNS lookup must not abort the batch.
                    report($exception);
                }
            });
    }
}
