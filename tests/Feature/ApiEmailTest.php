<?php

use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SesV2Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function larasendProjectFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'harborlight', 'slug' => 'harborlight']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'default_from_email' => 'receipts@example.com',
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'last_quota' => [
            'Max24HourSend' => 50000,
            'MaxSendRate' => 200,
            'SentLast24Hours' => 25,
        ],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);
    $issued = ApiKey::issue($project, 'Test key', $source);

    return [$workspace, $project, $source, $issued['plain_text']];
}

it('sends an email with api key auth and stores searchable content', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Welcome to Larasend',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
        'headers' => ['X-Test' => 'yes'],
        'tags' => ['kind' => 'welcome'],
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('object', 'email');

    $email = Email::query()->firstOrFail();

    expect($email->workspace_id)->toBe($workspace->id)
        ->and($email->project_id)->toBe($project->id)
        ->and($email->source_id)->toBe($source->id)
        ->and($email->status)->toBe('queued')
        ->and($email->ses_message_id)->toBeNull()
        ->and($email->recipients()->where('email', 'maya@example.com')->exists())->toBeTrue()
        ->and($email->events()->where('event_type', 'send')->exists())->toBeFalse();

    Queue::assertPushed(SendQueuedEmail::class, fn (SendQueuedEmail $job) => $job->emailId === $email->id);
});

it('refreshes stale ses quota before accepting api sends', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();
    $source->forceFill([
        'last_quota' => null,
        'last_quota_checked_at' => now()->subHours(7),
    ])->save();

    Queue::fake();

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function getAccount(Source $source): array
        {
            return [
                'SendQuota' => [
                    'Max24HourSend' => 50000,
                    'MaxSendRate' => 200,
                    'SentLast24Hours' => 25,
                ],
            ];
        }
    });

    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Quota refreshed',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ]);

    $response->assertAccepted();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($source->fresh()->last_quota['Max24HourSend'])->toBe(50000)
        ->and(Email::query()->where('subject', 'Quota refreshed')->exists())->toBeTrue();

    Queue::assertPushed(SendQueuedEmail::class);
});

it('accepts api sends when ses quota refresh fails', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();
    $source->forceFill([
        'last_quota' => null,
        'last_quota_checked_at' => null,
    ])->save();

    Queue::fake();

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function getAccount(Source $source): array
        {
            throw new RuntimeException('SES is temporarily unavailable.');
        }
    });

    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Quota refresh failed',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ]);

    $response->assertAccepted();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($source->fresh()->last_quota)->toBeNull()
        ->and(Email::query()->where('subject', 'Quota refresh failed')->exists())->toBeTrue();

    Queue::assertPushed(SendQueuedEmail::class);
});

it('sends queued email jobs through ses and records the response', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Welcome to Larasend',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    (new SendQueuedEmail($email->id))->handle(new class extends SesV2Client
    {
        public function sendRawEmail(Source $source, string $mime, array $destination = []): array
        {
            expect($mime)->toContain('Welcome to Larasend');

            return ['message_id' => 'ses-message-1', 'response' => ['MessageId' => 'ses-message-1']];
        }
    });

    expect($email->fresh())
        ->workspace_id->toBe($workspace->id)
        ->project_id->toBe($project->id)
        ->source_id->toBe($source->id)
        ->status->toBe('sent')
        ->ses_message_id->toBe('ses-message-1')
        ->and($email->events()->where('event_type', 'send')->exists())->toBeTrue();
});

it('sends bcc recipients through an explicit ses destination', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();
    Http::fake([
        'https://email.*.amazonaws.com/v2/email/outbound-emails' => Http::response(['MessageId' => 'ses-message-2']),
    ]);

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'cc' => ['Ana <ana@example.com>'],
        'bcc' => ['Bea <bea@example.com>'],
        'subject' => 'Monthly invoice',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    expect($email->recipients()->where('type', 'bcc')->pluck('email')->all())->toBe(['bea@example.com']);

    (new SendQueuedEmail($email->id))->handle(app(SesV2Client::class));

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v2/email/outbound-emails')) {
            return false;
        }

        $destination = $request->data()['Destination'] ?? [];

        return ($destination['ToAddresses'] ?? []) === ['maya@example.com']
            && ($destination['CcAddresses'] ?? []) === ['ana@example.com']
            && ($destination['BccAddresses'] ?? []) === ['bea@example.com'];
    });

    expect($email->fresh()->status)->toBe('sent')
        ->and($email->fresh()->ses_message_id)->toBe('ses-message-2')
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class);
});

it('blocks sends to suppressed recipients', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    Suppression::create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'maya@example.com',
        'reason' => 'complaint',
        'event_type' => 'complaint',
    ]);

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Welcome to Larasend',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('to');

    expect(Email::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lists and shows only emails scoped to the api key project', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    $email = Email::create([
        'public_id' => 'email_test',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'delivered',
        'from_email' => 'receipts@example.com',
        'subject' => 'Receipt',
    ]);
    $email->recipients()->create(['type' => 'to', 'email' => 'maya@example.com']);

    $this->withToken($token)
        ->getJson('/api/emails')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', 'email_test');

    $this->withToken($token)
        ->getJson('/api/emails/email_test')
        ->assertSuccessful()
        ->assertJsonPath('data.subject', 'Receipt');
});

it('rejects missing api keys', function () {
    $this->postJson('/api/emails', [])->assertUnauthorized();
});

it('rejects expired api keys', function () {
    [, $project, $source] = larasendProjectFixture();
    $issued = ApiKey::issue($project, 'Expired key', $source, ['send', 'read:activity'], now()->subDay());

    $this->withToken($issued['plain_text'])
        ->getJson('/api/emails')
        ->assertUnauthorized();
});

it('rejects api keys after they are deleted', function () {
    [, $project, $source] = larasendProjectFixture();
    $issued = ApiKey::issue($project, 'Revoked key', $source);
    $issued['api_key']->delete();

    $this->withToken($issued['plain_text'])
        ->getJson('/api/emails')
        ->assertUnauthorized();
});

it('prevents an api key from one project reading emails from another project', function () {
    [$workspaceA, $projectA, $sourceA, $tokenA] = larasendProjectFixture();

    $workspaceB = Workspace::create(['owner_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'other-workspace']);
    $projectB = Project::create(['workspace_id' => $workspaceB->id, 'name' => 'lighthouse', 'slug' => 'lighthouse']);
    $sourceB = Source::create([
        'project_id' => $projectB->id,
        'name' => 'Production',
        'webhook_token' => 'token-'.str()->random(8),
    ]);

    $emailB = Email::create([
        'public_id' => 'email_other_project',
        'workspace_id' => $workspaceB->id,
        'project_id' => $projectB->id,
        'source_id' => $sourceB->id,
        'status' => 'delivered',
        'from_email' => 'receipts@other.example',
        'subject' => 'Not yours',
    ]);

    expect($workspaceA)->toBeInstanceOf(Workspace::class)
        ->and($projectA)->toBeInstanceOf(Project::class)
        ->and($sourceA)->toBeInstanceOf(Source::class);

    $this->withToken($tokenA)
        ->getJson('/api/emails')
        ->assertSuccessful()
        ->assertJsonMissing(['id' => 'email_other_project']);

    $this->withToken($tokenA)
        ->getJson("/api/emails/{$emailB->public_id}")
        ->assertNotFound();
});

it('enforces api key scopes and records usage origin', function () {
    [$workspace, $project, $source] = larasendProjectFixture();
    $issued = ApiKey::issue($project, 'Read only key', $source, ['read:activity']);

    $this->withToken($issued['plain_text'])
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'LarasendTest/1.0'])
        ->getJson('/api/emails')
        ->assertSuccessful();

    $this->withToken($issued['plain_text'])
        ->postJson('/api/emails', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => ['Maya <maya@example.com>'],
            'subject' => 'Blocked by scope',
            'html' => '<h1>Hello Maya</h1>',
        ])
        ->assertForbidden();

    $apiKey = $issued['api_key']->fresh();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($apiKey?->last_used_at)->not->toBeNull()
        ->and($apiKey?->last_used_ip)->toBe('203.0.113.10')
        ->and($apiKey?->last_used_user_agent)->toBe('LarasendTest/1.0')
        ->and(Email::query()->where('subject', 'Blocked by scope')->exists())->toBeFalse();
});
