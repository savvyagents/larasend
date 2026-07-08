<?php

use App\Models\User;
use App\Support\ProjectContext;
use App\Support\SystemHealth;
use Illuminate\Support\Facades\Cache;

it('reports worker and scheduler heartbeats through the activity page', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $project->workspace->forceFill(['onboarded_at' => now(), 'setup_started_at' => now()])->save();

    app(SystemHealth::class)->recordWorkerHeartbeat();

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/setup")
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('system.worker_alive', true)
            ->where('system.scheduler_alive', false)
            ->where('system.stuck_queued', 0));
});

it('counts emails stuck in queued state when no worker runs', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();

    $project->emails()->create([
        'public_id' => 'email_stuckqueued',
        'workspace_id' => $project->workspace_id,
        'source_id' => $source->id,
        'environment' => 'prod',
        'status' => 'queued',
        'from_email' => 'receipts@example.com',
        'subject' => 'Stuck email',
        'text' => 'Never picked up.',
    ])->forceFill(['created_at' => now()->subMinutes(5)])->save();

    expect(app(SystemHealth::class)->stuckQueuedEmailCount($project))->toBe(1)
        ->and(app(SystemHealth::class)->workerIsAlive())->toBeFalse();
});

it('treats stale heartbeats as not alive', function () {
    Cache::put(SystemHealth::WORKER_HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String(), 600);
    Cache::put(SystemHealth::SCHEDULER_HEARTBEAT_KEY, now()->subMinute()->toIso8601String(), 600);

    expect(app(SystemHealth::class)->workerIsAlive())->toBeFalse()
        ->and(app(SystemHealth::class)->schedulerIsAlive())->toBeTrue();
});

it('runs the doctor command and reports failures with fixes', function () {
    $this->artisan('larasend:doctor')
        ->expectsOutputToContain('Queue worker running')
        ->assertExitCode(1);
});

it('passes the doctor command when heartbeats are fresh and nothing is configured wrong', function () {
    app(SystemHealth::class)->recordWorkerHeartbeat();
    app(SystemHealth::class)->recordSchedulerHeartbeat();

    $this->artisan('larasend:doctor')->assertExitCode(0);
});
