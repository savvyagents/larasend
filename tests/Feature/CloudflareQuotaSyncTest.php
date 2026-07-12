<?php

use App\Jobs\SyncStaleSourceQuotas;
use App\Models\User;
use App\Services\Providers\EmailProviderFactory;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\Http;

function cloudflareDashboardSourceFixture(User $user): array
{
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();

    $source->forceFill([
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-1234567890',
        'default_from_email' => 'receipts@example.com',
    ])->save();

    return [$project, $source];
}

it('syncs cloudflare daily quota into the normalized shape', function () {
    $user = User::factory()->create();
    [$project, $source] = cloudflareDashboardSourceFixture($user);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-1234567890/email/sending/limits' => Http::response([
            'success' => true,
            'result' => ['quota' => ['value' => 5000, 'unit' => 'day']],
        ]),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/source/quota")
        ->assertRedirect("/projects/{$project->slug}/source");

    $source->refresh();

    expect($source->last_quota_checked_at)->not->toBeNull()
        ->and($source->last_quota['max_24_hour_send'])->toBe(5000)
        ->and($source->last_quota['period'])->toBe('day');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cf-test-token'));
});

it('surfaces the workers paid plan entitlement error on quota sync', function () {
    $user = User::factory()->create();
    [$project, $source] = cloudflareDashboardSourceFixture($user);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-1234567890/email/sending/limits' => Http::response([
            'success' => false,
            'errors' => [['code' => 10105, 'message' => 'not entitled']],
        ], 403),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/source/quota")
        ->assertRedirect("/projects/{$project->slug}/source");

    $toast = session('inertia.flash_data')['toast'] ?? null;

    expect($toast)->not->toBeNull()
        ->and($toast['type'])->toBe('error')
        ->and($toast['message'])->toContain('Workers Paid plan')
        ->and($source->fresh()->last_quota)->toBeNull();
});

it('surfaces the missing token permission error on quota sync', function () {
    $user = User::factory()->create();
    [$project, $source] = cloudflareDashboardSourceFixture($user);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-1234567890/email/sending/limits' => Http::response([
            'success' => false,
            'errors' => [['code' => 10102, 'message' => 'forbidden']],
        ], 403),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/source/quota")
        ->assertRedirect("/projects/{$project->slug}/source");

    $toast = session('inertia.flash_data')['toast'] ?? null;

    expect($toast)->not->toBeNull()
        ->and($toast['message'])->toContain('Email Sending: Edit');
});

it('refreshes stale source quotas automatically via the scheduled job', function () {
    $user = User::factory()->create();
    [$project, $source] = cloudflareDashboardSourceFixture($user);
    $source->forceFill(['last_quota' => null, 'last_quota_checked_at' => now()->subHours(7)])->save();

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-1234567890/email/sending/limits' => Http::response([
            'success' => true,
            'result' => ['quota' => ['value' => 9000, 'unit' => 'day']],
        ]),
    ]);

    (new SyncStaleSourceQuotas)->handle(app(EmailProviderFactory::class));

    expect($source->fresh()->last_quota['max_24_hour_send'])->toBe(9000);
});

it('skips fresh quotas in the scheduled sync job', function () {
    $user = User::factory()->create();
    [$project, $source] = cloudflareDashboardSourceFixture($user);
    $source->forceFill([
        'last_quota' => ['max_24_hour_send' => 111, 'period' => 'day'],
        'last_quota_checked_at' => now()->subHour(),
    ])->save();

    Http::fake();

    (new SyncStaleSourceQuotas)->handle(app(EmailProviderFactory::class));

    Http::assertNothingSent();

    expect($source->fresh()->last_quota['max_24_hour_send'])->toBe(111);
});
