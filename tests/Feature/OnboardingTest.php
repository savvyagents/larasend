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

it('renders first-run onboarding with docker and ses setup guidance', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/onboarding')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding')
            ->has('workspace')
            ->where('workspace.setup_started_at', null)
            ->has('project')
            ->where('project.has_started_setup', false)
            ->where('project.resume_path', '/projects/my-project/setup')
            ->where('project.credential_mode', 'configure_later')
            ->where('project.next_step.key', 'source')
            ->has('source.webhook_url')
            ->has('install.compose')
            ->where('install.compose', 'docker compose up -d')
            ->has('progress', 5)
        );
});

it('routes fresh users from dashboard into onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/onboarding');
});

it('stores onboarding setup, creates a domain, api key, and webhook endpoint', function () {
    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/account' => Http::response([
            'SendQuota' => ['Max24HourSend' => 50000, 'MaxSendRate' => 200, 'SentLast24Hours' => 25],
            'ProductionAccessEnabled' => true,
        ]),
        'https://email.us-east-1.amazonaws.com/v2/email/identities' => Http::response([
            'DkimAttributes' => [
                'Tokens' => ['abc123', 'def456', 'ghi789'],
            ],
        ]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/onboarding', [
            'workspace_name' => 'Acme Mail',
            'project_name' => 'Billing',
            'project_slug' => 'billing',
            'credential_mode' => 'aws_keys',
            'source_name' => 'Production',
            'environment' => 'prod',
            'ses_region' => 'us-east-1',
            'ses_configuration_set' => 'larasend-prod',
            'default_from_name' => 'Acme Billing',
            'default_from_email' => 'receipts@example.com',
            'aws_access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'aws_secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'aws_session_token' => 'session-token-example',
            'sending_domain' => 'mail.example.com',
            'create_api_key' => true,
            'api_key_name' => 'Billing production',
            'webhook_url' => 'https://example.com/hooks/larasend',
        ])
        ->assertRedirect('/projects/billing/setup')
        ->assertSessionHas('newApiKey');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Amz-Security-Token', 'session-token-example'));

    $workspace = $user->workspaces()->firstOrFail();
    $project = $workspace->projects()->where('slug', 'billing')->firstOrFail();
    $source = $project->sources()->firstOrFail();

    expect($workspace->name)->toBe('Acme Mail')
        ->and($workspace->onboarded_at)->toBeNull()
        ->and($workspace->setup_started_at)->not->toBeNull()
        ->and($project->name)->toBe('Billing')
        ->and($source->default_from_email)->toBe('receipts@example.com')
        ->and($source->aws_session_token)->toBe('session-token-example')
        ->and($project->domains()->where('domain', 'mail.example.com')->exists())->toBeTrue()
        ->and($project->domains()->where('domain', 'mail.example.com')->firstOrFail()->dns_records)->toHaveCount(3)
        ->and($project->domains()->where('domain', 'mail.example.com')->firstOrFail()->dns_records[0]['value'])->toBe('abc123.dkim.amazonses.com')
        ->and($project->apiKeys()->where('name', 'Billing production')->exists())->toBeTrue()
        ->and($project->webhookEndpoints()->where('url', 'https://example.com/hooks/larasend')->exists())->toBeTrue();
});

it('saves configure later onboarding and resumes incomplete users in project setup', function () {
    Http::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/onboarding', [
            'workspace_name' => 'Acme Mail',
            'project_name' => 'Billing',
            'project_slug' => 'billing',
            'credential_mode' => 'configure_later',
            'source_name' => 'Production',
            'environment' => 'prod',
            'ses_region' => 'us-east-1',
            'default_from_name' => 'Acme Billing',
            'default_from_email' => null,
            'sending_domain' => null,
            'create_api_key' => false,
        ])
        ->assertRedirect('/projects/billing/setup');

    Http::assertNothingSent();

    $workspace = $user->workspaces()->firstOrFail();
    $project = $workspace->projects()->where('slug', 'billing')->firstOrFail();
    $source = $project->sources()->firstOrFail();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/projects/billing/setup');

    expect($workspace->name)->toBe('Acme Mail')
        ->and($workspace->setup_started_at)->not->toBeNull()
        ->and($workspace->onboarded_at)->toBeNull()
        ->and($source->aws_access_key_id)->toBeNull()
        ->and($source->aws_secret_access_key)->toBeNull()
        ->and($source->default_from_email)->toBeNull()
        ->and($project->domains()->exists())->toBeFalse();
});

it('returns sending domain errors inline when ses identity creation fails', function () {
    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/account' => Http::response([
            'SendQuota' => ['Max24HourSend' => 50000, 'MaxSendRate' => 200, 'SentLast24Hours' => 25],
            'ProductionAccessEnabled' => true,
        ]),
        'https://email.us-east-1.amazonaws.com/v2/email/identities' => Http::response([
            'message' => 'Identity creation failed.',
        ], 500),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/onboarding')
        ->post('/onboarding', [
            'workspace_name' => 'Acme Mail',
            'project_name' => 'Billing',
            'project_slug' => 'billing',
            'credential_mode' => 'aws_keys',
            'source_name' => 'Production',
            'environment' => 'prod',
            'ses_region' => 'us-east-1',
            'default_from_name' => 'Acme Billing',
            'default_from_email' => 'receipts@example.com',
            'aws_access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'aws_secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'sending_domain' => 'mail.example.com',
            'create_api_key' => false,
        ])
        ->assertRedirect('/onboarding')
        ->assertSessionHasErrors('sending_domain');

    $workspace = $user->workspaces()->firstOrFail();
    $project = $workspace->projects()->where('slug', 'billing')->firstOrFail();
    $source = $project->sources()->firstOrFail();

    expect($workspace->name)->toBe('Acme Mail')
        ->and($workspace->setup_started_at)->not->toBeNull()
        ->and($project->name)->toBe('Billing')
        ->and($source->default_from_email)->toBe('receipts@example.com')
        ->and($project->domains()->exists())->toBeFalse();
});

it('routes started users to setup until setup is activated by a verified domain, api key, and real send', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/onboarding', [
            'workspace_name' => 'Acme Mail',
            'project_name' => 'Billing',
            'project_slug' => 'billing',
            'credential_mode' => 'aws_keys',
            'source_name' => 'Production',
            'environment' => 'prod',
            'ses_region' => 'us-east-1',
            'default_from_name' => 'Acme Billing',
            'default_from_email' => 'receipts@example.com',
            'aws_access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'aws_secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'create_api_key' => true,
        ])
        ->assertRedirect('/projects/billing/setup');

    $workspace = $user->workspaces()->firstOrFail();
    $project = $workspace->projects()->where('slug', 'billing')->firstOrFail();
    $source = $project->sources()->firstOrFail();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/projects/billing/setup');

    $project->domains()->create([
        'domain' => 'mail.example.com',
        'status' => 'verified',
        'verified_at' => now(),
        'dns_records' => [],
    ]);

    $project->emails()->create([
        'public_id' => 'email_testactivation',
        'workspace_id' => $workspace->id,
        'source_id' => $source->id,
        'environment' => 'prod',
        'status' => 'sent',
        'from_email' => 'receipts@example.com',
        'subject' => 'Activation test',
        'text' => 'Sent through Larasend.',
        'sent_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/projects/billing/activity');

    expect($workspace->fresh()->onboarded_at)->not->toBeNull();
});

it('validates cloudflare credentials live and returns zones for the wizard', function () {
    $user = User::factory()->create();
    app(ProjectContext::class)->projectFor($user);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'zone-1',
                'name' => 'example.com',
                'account' => ['id' => 'acc-abc', 'name' => 'Test Account'],
            ]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/limits' => Http::response([
            'success' => true,
            'result' => ['quota' => ['value' => 3000, 'unit' => 'day']],
        ]),
    ]);

    $this->actingAs($user)
        ->postJson('/onboarding/validate', [
            'provider' => 'cloudflare',
            'cloudflare_api_token' => 'cf-wizard-token',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.account_id', 'acc-abc')
        ->assertJsonPath('meta.zones.0.name', 'example.com')
        ->assertJsonPath('meta.quota.max_24_hour_send', 3000);
});

it('returns actionable blockers when cloudflare credentials cannot send', function () {
    $user = User::factory()->create();
    app(ProjectContext::class)->projectFor($user);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'zone-1',
                'name' => 'example.com',
                'account' => ['id' => 'acc-abc', 'name' => 'Test Account'],
            ]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/limits' => Http::response([
            'success' => false,
            'errors' => [['code' => 10105, 'message' => 'not entitled']],
        ], 403),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/onboarding/validate', [
            'provider' => 'cloudflare',
            'cloudflare_api_token' => 'cf-wizard-token',
        ])
        ->assertOk()
        ->assertJsonPath('ok', false);

    expect($response->json('blockers.0.message'))->toContain('Workers Paid plan');
});

it('validates ses credentials live and surfaces sandbox mode', function () {
    $user = User::factory()->create();
    app(ProjectContext::class)->projectFor($user);

    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/account' => Http::response([
            'SendQuota' => ['Max24HourSend' => 200, 'MaxSendRate' => 1, 'SentLast24Hours' => 0],
            'ProductionAccessEnabled' => false,
        ]),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/onboarding/validate', [
            'provider' => 'ses',
            'ses_region' => 'us-east-1',
            'aws_access_key_id' => 'test-key',
            'aws_secret_access_key' => 'test-secret',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.quota.max_24_hour_send', 200);

    expect($response->json('warnings.0.code'))->toBe('ses_sandbox');
});

it('stores onboarding with defaults when optional names are omitted', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'zone-1',
                'name' => 'example.com',
                'account' => ['id' => 'acc-derived', 'name' => 'Test Account'],
            ]],
        ]),
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/limits' => Http::response([
            'success' => true,
            'result' => ['quota' => ['value' => 3000, 'unit' => 'day']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/email/sending/subdomains' => Http::response([
            'success' => true,
            'result' => [],
        ]),
    ]);

    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $originalName = $project->name;

    $this->actingAs($user)
        ->post('/onboarding', [
            'credential_mode' => 'cloudflare_token',
            'source_name' => 'Production',
            'environment' => 'prod',
            'cloudflare_account_id' => null,
            'cloudflare_api_token' => 'cf-wizard-token',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'notifications@mail.example.com',
            'create_api_key' => true,
            'api_key_name' => 'Production key',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $project->refresh();
    $source = $project->sources()->firstOrFail();

    expect($project->name)->toBe($originalName)
        ->and($source->provider->value)->toBe('cloudflare')
        ->and($source->cloudflare_account_id)->toBe('acc-derived')
        ->and($source->last_quota['max_24_hour_send'])->toBe(3000)
        ->and($project->apiKeys()->where('name', 'Production key')->exists())->toBeTrue();
});
