<?php

use App\Models\User;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Domain rechecks dispatched during these tests run inline on the sync
    // queue; stub the DoH resolvers so no real DNS traffic leaves the suite.
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response(['Status' => 3, 'Answer' => []]),
        'https://dns.google/*' => Http::response(['Status' => 3, 'Answer' => []]),
    ]);
});

function fakeCloudflareCredentialProbes(): void
{
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'zone-1',
                'name' => 'example.com',
                'account' => ['id' => 'acc-1234567890', 'name' => 'Test Account'],
            ]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/limits' => Http::response([
            'success' => true,
            'result' => ['quota' => ['value' => 5000, 'unit' => 'day']],
        ]),
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['id' => 'tok-1', 'status' => 'active'],
        ]),
        'https://email.us-east-1.amazonaws.com/v2/email/account' => Http::response([
            'SendQuota' => ['Max24HourSend' => 50000, 'MaxSendRate' => 200, 'SentLast24Hours' => 25],
            'ProductionAccessEnabled' => true,
        ]),
    ]);
}

it('switches a source to cloudflare without requiring an ses region', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);

    fakeCloudflareCredentialProbes();

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_account_id' => 'acc-1234567890',
            'cloudflare_api_token' => 'cf-test-token',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertRedirect('/source')
        ->assertSessionHasNoErrors();

    $source = $project->sources()->firstOrFail();

    expect($source->provider->value)->toBe('cloudflare')
        ->and($source->cloudflare_account_id)->toBe('acc-1234567890')
        ->and($source->cloudflare_api_token)->toBe('cf-test-token')
        ->and($source->last_quota['max_24_hour_send'])->toBe(5000);
});

it('derives the cloudflare account id from the token when left blank', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);

    fakeCloudflareCredentialProbes();

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_api_token' => 'cf-test-token',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertRedirect('/source')
        ->assertSessionHasNoErrors();

    expect($project->sources()->firstOrFail()->cloudflare_account_id)->toBe('acc-1234567890');
});

it('rejects a cloudflare token that cannot access email sending', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'zone-1',
                'name' => 'example.com',
                'account' => ['id' => 'acc-1234567890', 'name' => 'Test Account'],
            ]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/limits' => Http::response([
            'success' => false,
            'errors' => [['code' => 2036, 'message' => 'Unauthorized']],
        ], 401),
    ]);

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_api_token' => 'cf-no-email-permission',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertSessionHasErrors('cloudflare_api_token');

    $source = $project->sources()->firstOrFail();

    expect($source->provider->value)->toBe('ses')
        ->and(session('errors')->first('cloudflare_api_token'))
        ->toContain('Workers Paid plan');
});

it('rejects an invalid cloudflare token at save time', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://api.cloudflare.com/client/v4/*' => Http::response([
            'success' => false,
            'errors' => [['code' => 1000, 'message' => 'Invalid API Token']],
        ], 401),
    ]);

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_api_token' => 'not-a-real-token',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertSessionHasErrors('cloudflare_api_token');
});

it('requires an api token the first time a source switches to cloudflare', function () {
    $user = User::factory()->create();
    app(ProjectContext::class)->projectFor($user);

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_account_id' => 'acc-1234567890',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertSessionHasErrors('cloudflare_api_token');
});

it('keeps the stored cloudflare token when the field is left blank', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-original-token',
        'cloudflare_account_id' => 'acc-1234567890',
    ])->save();

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_account_id' => 'acc-1234567890',
            'cloudflare_api_token' => '',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertSessionHasNoErrors();

    expect($source->fresh()->cloudflare_api_token)->toBe('cf-original-token');
});

it('keeps ses credentials when switching a source to cloudflare and back', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'aws_access_key_id' => 'aws-key',
        'aws_secret_access_key' => 'aws-secret',
    ])->save();

    fakeCloudflareCredentialProbes();

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'cloudflare',
            'cloudflare_account_id' => 'acc-1234567890',
            'cloudflare_api_token' => 'cf-test-token',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertSessionHasNoErrors();

    $source->refresh();

    expect($source->provider->value)->toBe('cloudflare')
        ->and($source->aws_access_key_id)->toBe('aws-key');

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'provider' => 'ses',
            'ses_region' => 'us-east-1',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 90,
        ])
        ->assertSessionHasNoErrors();

    $source->refresh();

    expect($source->provider->value)->toBe('ses')
        ->and($source->aws_access_key_id)->toBe('aws-key')
        ->and($source->cloudflare_api_token)->toBe('cf-test-token');
});

it('onboards the sending domain in cloudflare and publishes its dns records automatically', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-1234567890',
        'default_from_email' => 'receipts@example.com',
    ])->save();

    Http::fake(function ($request) {
        $url = $request->url();
        $method = $request->method();

        if (str_contains($url, '/zones?') || str_ends_with($url, '/zones')) {
            // The zone is the apex; the subdomain lookup finds nothing first.
            return str_contains($url, 'name=mail.example.com')
                ? Http::response(['success' => true, 'result' => []])
                : Http::response(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com']]]);
        }

        if (str_ends_with($url, '/zones/zone-1/email/sending/subdomains') && $method === 'GET') {
            return Http::response(['success' => true, 'result' => []]);
        }

        if (str_ends_with($url, '/zones/zone-1/email/sending/subdomains') && $method === 'POST') {
            return Http::response(['success' => true, 'result' => [
                'tag' => 'sub-1',
                'name' => 'mail.example.com',
                'enabled' => true,
                'dkim_selector' => 'cf2199-7',
            ]]);
        }

        if (str_ends_with($url, '/zones/zone-1/email/sending/subdomains/sub-1/dns')) {
            return Http::response(['success' => true, 'result' => [
                ['type' => 'txt', 'name' => 'mail.example.com', 'content' => 'v=spf1 include:_spf.mx.cloudflare.net ~all'],
                ['type' => 'txt', 'name' => 'cf2199-7._domainkey.mail.example.com', 'content' => 'v=DKIM1; p=abc123'],
                ['type' => 'mx', 'name' => 'mail.example.com', 'content' => 'route1.mx.cloudflare.net', 'priority' => 5],
            ]]);
        }

        if (str_ends_with($url, '/zones/zone-1/dns_records') && $method === 'POST') {
            return Http::response(['success' => true, 'result' => ['id' => 'rec-'.str()->random(4)]]);
        }

        return Http::response(['success' => false, 'errors' => [['code' => 9999, 'message' => "unexpected call to {$url}"]]], 500);
    });

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'mail.example.com'])
        ->assertRedirect('/identities');

    $domain = $project->domains()->where('domain', 'mail.example.com')->firstOrFail();
    $records = collect($domain->dns_records);

    expect($domain->status)->toBe('pending')
        ->and($records->pluck('value'))->toContain('v=spf1 include:_spf.mx.cloudflare.net ~all')
        ->and($records->pluck('name'))->toContain('cf2199-7._domainkey.mail.example.com')
        ->and($records->firstWhere('type', 'MX')['value'])->toBe('5 route1.mx.cloudflare.net');

    // 2 zone lookups, list + create subdomain, dns expectations, 3 record
    // creations, then 3 DoH lookups from the inline recheck job.
    Http::assertSentCount(11);
});

it('falls back to manual verification records when the token lacks zone permissions', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-limited-token',
        'cloudflare_account_id' => 'acc-1234567890',
        'default_from_email' => 'receipts@example.com',
    ])->save();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => false,
            'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']],
        ], 403),
    ]);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'mail.example.com'])
        ->assertRedirect('/identities');

    $domain = $project->domains()->where('domain', 'mail.example.com')->firstOrFail();
    $values = collect($domain->dns_records)->pluck('value');

    expect($domain->status)->toBe('pending')
        ->and($values)->toContain('_spf.mx.cloudflare.net')
        ->and($values)->toContain('v=DKIM1')
        ->and(collect($domain->dns_records)->pluck('name'))->toContain('cf2024-1._domainkey.mail.example.com');
});

it('never exposes the cloudflare token through model serialization', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-secret-token',
        'cloudflare_account_id' => 'acc-1234567890',
    ])->save();

    $serialized = $source->fresh()->toArray();

    expect($serialized)->not->toHaveKey('cloudflare_api_token')
        ->and($serialized)->not->toHaveKey('cloudflare_account_id')
        ->and(json_encode($serialized))->not->toContain('cf-secret-token');
});
