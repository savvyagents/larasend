<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

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
            ->where('install.compose', 'docker compose up --build -d')
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
