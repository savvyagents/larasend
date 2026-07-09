<?php

use App\Jobs\RecheckPendingDomains;
use App\Jobs\SyncCloudflareSuppressions;
use App\Jobs\SyncStaleSourceQuotas;
use App\Support\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(SystemHealth::class)->recordSchedulerHeartbeat())
    ->everyMinute()
    ->name('scheduler-heartbeat');
Schedule::job(new SyncCloudflareSuppressions)->hourly();
Schedule::job(new RecheckPendingDomains)->everyTenMinutes()->withoutOverlapping();
Schedule::job(new SyncStaleSourceQuotas)->everyThirtyMinutes()->withoutOverlapping();
