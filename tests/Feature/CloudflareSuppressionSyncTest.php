<?php

use App\Jobs\SyncCloudflareSuppressions;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CloudflareApiClient;
use Illuminate\Support\Facades\Http;

function cloudflareSuppressionSource(string $slug, string $accountId): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $slug, 'slug' => $slug]);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => $slug, 'slug' => $slug]);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => "token-{$slug}",
        'cloudflare_account_id' => $accountId,
        'default_from_email' => 'receipts@example.com',
        'webhook_token' => 'token-'.str()->random(8),
    ]);

    return [$project, $source];
}

it('pulls cloudflare suppressions into the project with mapped reasons', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-sync', 'acc-sync');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-sync/email/sending/suppression*' => Http::sequence()
            ->push([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'Bounced@Example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                    ['id' => 'sup-2', 'email' => 'complainer@example.com', 'reason' => 'spam_complaint', 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addMonth()->toIso8601String()],
                ],
            ])
            ->push(['success' => true, 'result' => []]),
    ]);

    (new SyncCloudflareSuppressions)->handle(app(CloudflareApiClient::class));

    $bounced = Suppression::query()->where('email', 'bounced@example.com')->firstOrFail();
    $complained = Suppression::query()->where('email', 'complainer@example.com')->firstOrFail();

    expect($bounced)
        ->project_id->toBe($project->id)
        ->workspace_id->toBe($project->workspace_id)
        ->source_id->toBe($source->id)
        ->reason->toBe('hard_bounce')
        ->event_type->toBe('provider_sync')
        ->expires_at->toBeNull()
        ->and($complained->reason)->toBe('complaint')
        ->and($complained->expires_at)->not->toBeNull();
});

it('is idempotent across repeated sync runs', function () {
    cloudflareSuppressionSource('cf-idem', 'acc-idem');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-idem/email/sending/suppression*' => Http::sequence()
            ->push([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'repeat@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ])
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'repeat@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ])
            ->push(['success' => true, 'result' => []]),
    ]);

    (new SyncCloudflareSuppressions)->handle(app(CloudflareApiClient::class));
    (new SyncCloudflareSuppressions)->handle(app(CloudflareApiClient::class));

    expect(Suppression::query()->where('email', 'repeat@example.com')->count())->toBe(1);
});

it('continues syncing other sources when one token fails', function () {
    cloudflareSuppressionSource('cf-bad', 'acc-bad');
    cloudflareSuppressionSource('cf-good', 'acc-good');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-bad/email/sending/suppression*' => Http::response([
            'success' => false,
            'errors' => [['code' => 10001, 'message' => 'invalid token']],
        ], 401),
        'https://api.cloudflare.com/client/v4/accounts/acc-good/email/sending/suppression*' => Http::sequence()
            ->push([
                'success' => true,
                'result' => [
                    ['id' => 'sup-9', 'email' => 'survivor@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ])
            ->push(['success' => true, 'result' => []]),
    ]);

    (new SyncCloudflareSuppressions)->handle(app(CloudflareApiClient::class));

    expect(Suppression::query()->where('email', 'survivor@example.com')->exists())->toBeTrue();
});
