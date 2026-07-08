<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Source;
use App\Services\Providers\EmailProviderFactory;
use App\Support\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('larasend:doctor')]
#[Description('Check everything a working Larasend install depends on and report exactly what is broken')]
class DoctorCommand extends Command
{
    private bool $healthy = true;

    public function handle(EmailProviderFactory $providers, SystemHealth $health): int
    {
        $this->line('Larasend doctor');
        $this->newLine();

        $this->check('APP_KEY is set', filled(config('app.key')), 'Run: php artisan key:generate');

        try {
            DB::connection()->getPdo();
            $this->check('Database connection', true);
        } catch (Throwable $exception) {
            $this->check('Database connection', false, $exception->getMessage());

            return self::FAILURE;
        }

        $this->check(
            'Queue worker running',
            $health->workerIsAlive(),
            'Emails will stay "queued". Run: php artisan queue:work',
        );

        $this->check(
            'Scheduler running',
            $health->schedulerIsAlive(),
            'DNS re-checks, quota, and suppression sync will not run. Run: php artisan schedule:work (or add the schedule:run cron entry)',
        );

        $pendingJobs = (int) DB::table('jobs')->count();

        $this->check(
            $pendingJobs === 0 ? 'Queue is empty' : "Queue has {$pendingJobs} pending job(s)",
            $pendingJobs === 0 || $health->workerIsAlive(),
            'Jobs are waiting but no worker is processing them',
        );

        Source::query()->with('project')->each(function (Source $source) use ($providers): void {
            $label = "{$source->project?->name}: {$source->provider->label()} credentials";
            $provider = $providers->forSource($source);

            if (! $provider->hasSendingCredentials($source)) {
                $this->check($label, false, 'No credentials configured for this source');

                return;
            }

            try {
                $result = $provider->validateCredentials($source);
            } catch (Throwable $exception) {
                $this->check($label, false, $exception->getMessage());

                return;
            }

            $this->check(
                $label,
                $result['ok'],
                collect($result['blockers'])->pluck('message')->implode(' '),
            );

            foreach ($result['warnings'] as $warning) {
                $this->warn("  ! {$warning['message']}");
            }
        });

        Domain::query()->each(function (Domain $domain): void {
            $this->check(
                "Domain {$domain->domain}",
                $domain->status === 'verified' || $domain->status === 'local',
                'DNS not verified yet — records are re-checked automatically every 10 minutes while the scheduler runs',
            );
        });

        $this->newLine();

        if ($this->healthy) {
            $this->info('Everything looks healthy.');

            return self::SUCCESS;
        }

        $this->error('Some checks failed — fixes are listed next to each failure above.');

        return self::FAILURE;
    }

    private function check(string $label, bool $passed, string $hint = ''): void
    {
        if ($passed) {
            $this->line("  <fg=green>✓</> {$label}");

            return;
        }

        $this->healthy = false;
        $this->line("  <fg=red>✗</> {$label}".($hint !== '' ? " — {$hint}" : ''));
    }
}
