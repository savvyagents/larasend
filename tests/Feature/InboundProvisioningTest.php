<?php

use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function provisioningFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Prov Co', 'slug' => 'prov-co']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'provbox', 'slug' => 'provbox']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-prov',
        'default_from_email' => 'notifications@mail.example.com',
        'webhook_token' => 'prov-token-'.str()->random(8),
    ]);
    $domain = $project->domains()->create([
        'domain' => 'mail.example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);

    return [$user, $project, $source, $domain];
}

it('provisions cloudflare inbound end to end: worker, routing, catch-all', function () {
    [$user, $project, $source, $domain] = provisioningFixture();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-9', 'name' => 'example.com', 'account' => ['id' => 'acc-prov', 'name' => 'Prov']]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/acc-prov/workers/scripts/larasend-inbound' => Http::response(['success' => true, 'result' => ['id' => 'larasend-inbound']]),
        'https://api.cloudflare.com/client/v4/zones/zone-9/email/routing/enable' => Http::response(['success' => true, 'result' => ['enabled' => true]]),
        'https://api.cloudflare.com/client/v4/zones/zone-9/email/routing/rules/catch_all' => Http::response(['success' => true, 'result' => ['enabled' => true]]),
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [['name' => 'example.com', 'type' => 15, 'data' => '20 route1.mx.cloudflare.net.']],
        ]),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/domains/{$domain->id}/inbound")
        ->assertRedirect("/projects/{$project->slug}/identities");

    expect($domain->fresh()->inbound_enabled_at)->not->toBeNull();

    Http::assertSent(function ($request) use ($source) {
        if (! str_contains($request->url(), '/workers/scripts/larasend-inbound')) {
            return false;
        }

        $body = (string) $request->body();

        return str_contains($body, 'LARASEND_INBOUND_URL')
            && str_contains($body, $source->webhook_token)
            && str_contains($body, 'async email(message, env)');
    });

    Http::assertSent(fn ($request) => str_contains($request->url(), '/email/routing/rules/catch_all')
        && ($request->data()['actions'][0]['type'] ?? null) === 'worker'
        && ($request->data()['actions'][0]['value'][0] ?? null) === 'larasend-inbound');
});

it('surfaces the missing worker permission with manual instructions', function () {
    [$user, $project, $source, $domain] = provisioningFixture();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-9', 'name' => 'example.com', 'account' => ['id' => 'acc-prov', 'name' => 'Prov']]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/acc-prov/workers/scripts/larasend-inbound' => Http::response([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
        ], 403),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/domains/{$domain->id}/inbound")
        ->assertRedirect("/projects/{$project->slug}/identities");

    $toast = session('inertia.flash_data')['toast'] ?? null;

    expect($domain->fresh()->inbound_enabled_at)->toBeNull()
        ->and($toast['type'])->toBe('error')
        ->and($toast['message'])->toContain('Workers Scripts: Edit')
        ->and(session('inboundError'))->toContain('Workers Scripts: Edit');
});

it('refuses to enable inbound for non-cloudflare sources', function () {
    [$user, $project, $source, $domain] = provisioningFixture();
    $source->forceFill(['provider' => 'ses'])->save();

    Http::fake();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/domains/{$domain->id}/inbound")
        ->assertRedirect("/projects/{$project->slug}/identities");

    Http::assertNothingSent();

    expect($domain->fresh()->inbound_enabled_at)->toBeNull();
});

it('publishes routing mx records directly when the enable endpoint is forbidden', function () {
    [$user, $project, $source, $domain] = provisioningFixture();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-9', 'name' => 'example.com', 'account' => ['id' => 'acc-prov', 'name' => 'Prov']]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/acc-prov/workers/scripts/larasend-inbound' => Http::response(['success' => true, 'result' => ['id' => 'larasend-inbound']]),
        'https://api.cloudflare.com/client/v4/zones/zone-9/email/routing/rules/catch_all' => Http::response(['success' => true, 'result' => ['enabled' => true]]),
        'https://api.cloudflare.com/client/v4/zones/zone-9/email/routing/enable' => Http::response([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
        ], 403),
        'https://cloudflare-dns.com/*' => Http::response(['Status' => 3, 'Answer' => []]),
        'https://dns.google/*' => Http::response(['Status' => 3, 'Answer' => []]),
        'https://api.cloudflare.com/client/v4/zones/zone-9/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/domains/{$domain->id}/inbound")
        ->assertRedirect("/projects/{$project->slug}/identities");

    expect($domain->fresh()->inbound_enabled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/zones/zone-9/dns_records')
        && ($request->data()['type'] ?? null) === 'MX'
        && str_contains((string) ($request->data()['content'] ?? ''), 'mx.cloudflare.net'));
});
