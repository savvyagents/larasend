<?php

use App\Jobs\RecoverStuckQueuedEmails;
use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailSendService;
use App\Services\MimeMessageBuilder;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesV2Client;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

it('rejects custom header names that are not rfc 5322 tokens', function (string $headerName) {
    [, , , $token] = larasendProjectFixture();

    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['maya@example.com'],
        'subject' => 'Header smuggling attempt',
        'text' => 'Hello',
        'headers' => [$headerName => 'value'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('headers');

    expect(Email::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'crlf injected name' => "X-A: x\r\nFrom: spoof@attacker.example",
    'bare line feed' => "X-Test\nBcc: spoof@attacker.example",
    'bare carriage return' => "X-Test\r",
    'colon in name' => 'X-Test: extra',
    'space in name' => 'X Test',
]);

it('refuses to build mime containing CR or LF in custom headers', function (array $headers) {
    expect(fn () => app(MimeMessageBuilder::class)->build(
        from: 'receipts@example.com',
        to: ['maya@example.com'],
        cc: [],
        bcc: [],
        replyTo: null,
        subject: 'Defense in depth',
        html: null,
        text: 'Hello',
        headers: $headers,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'crlf in name' => [["X-A: x\r\nFrom: spoof@attacker.example" => 'value']],
    'crlf in value' => [['X-Test' => "safe\r\nBcc: spoof@attacker.example"]],
]);

it('stores queued mime on the configured shared disk', function () {
    [, , , $token] = larasendProjectFixture();
    config(['larasend.mime_disk' => 'shared-mail']);
    Storage::fake('shared-mail');
    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['maya@example.com'],
        'subject' => 'Shared storage test',
        'text' => 'The queue worker can read this MIME.',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    expect($email->mime_disk)->toBe('shared-mail');
    Storage::disk('shared-mail')->assertExists($email->mime_path);
});

it('keeps committed outbound mime when queue dispatch is temporarily unavailable', function () {
    [, $project, $source] = larasendProjectFixture();
    config(['larasend.mime_disk' => 'shared-mail']);
    Storage::fake('shared-mail');
    $originalDispatcher = app(Dispatcher::class);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('getCommandHandler')->andReturnNull();
    $dispatcher->shouldReceive('dispatchNow')->andReturnUsing(fn (object $command): object => $command);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable.'));
    $this->app->instance(Dispatcher::class, $dispatcher);

    try {
        expect(fn () => app(EmailSendService::class)->send($project, $source, [
            'from' => 'Larasend <receipts@example.com>',
            'to' => ['maya@example.com'],
            'subject' => 'Durable queue handoff',
            'text' => 'Keep this MIME for retry.',
        ]))->toThrow(RuntimeException::class, 'Queue unavailable.');
    } finally {
        $this->app->instance(Dispatcher::class, $originalDispatcher);
    }

    $email = Email::query()->where('subject', 'Durable queue handoff')->firstOrFail();

    expect($email->status)->toBe('queued');
    Storage::disk('shared-mail')->assertExists($email->mime_path);

    $email->forceFill(['created_at' => now()->subMinutes(3)])->saveQuietly();
    $recentEmail = $email->replicate();
    $recentEmail->forceFill([
        'public_id' => 'email_'.str()->random(24),
        'subject' => 'Recent queue handoff',
        'created_at' => now(),
        'updated_at' => now(),
    ])->saveQuietly();
    Queue::fake();

    (new RecoverStuckQueuedEmails)->handle();

    Queue::assertPushed(
        SendQueuedEmail::class,
        fn (SendQueuedEmail $job): bool => $job->emailId === $email->id,
    );
    Queue::assertNotPushed(
        SendQueuedEmail::class,
        fn (SendQueuedEmail $job): bool => $job->emailId === $recentEmail->id,
    );
});

it('schedules unique queued email recovery without overlapping', function () {
    $job = new RecoverStuckQueuedEmails;
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === RecoverStuckQueuedEmails::class);
    $retryAfterValues = collect(config('queue.connections'))
        ->pluck('retry_after')
        ->filter(fn ($retryAfter): bool => is_int($retryAfter));

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($retryAfterValues->every(fn (int $retryAfter): bool => $job->timeout < $retryAfter))->toBeTrue()
        ->and($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('replays idempotent sends without queueing a duplicate email', function () {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    $payload = [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['maya@example.com'],
        'subject' => 'Idempotent receipt',
        'text' => 'One message only.',
    ];

    $first = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'order-4821')
        ->postJson('/api/emails', $payload)
        ->assertAccepted();
    $second = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'order-4821')
        ->postJson('/api/emails', $payload)
        ->assertAccepted()
        ->assertJsonPath('replayed', true);

    expect(Email::query()->count())->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'));
    Queue::assertPushed(SendQueuedEmail::class, 1);
});

it('rejects reusing an idempotency key with a different payload', function () {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    $request = $this->withToken($token)->withHeader('Idempotency-Key', 'order-4821');

    $request->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['maya@example.com'],
        'subject' => 'Original',
        'text' => 'Original body.',
    ])->assertAccepted();

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'order-4821')
        ->postJson('/api/emails', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => ['maya@example.com'],
            'subject' => 'Changed',
            'text' => 'Changed body.',
        ])
        ->assertConflict();

    expect(Email::query()->count())->toBe(1);
});

it('replays the winning email when concurrent idempotent inserts race', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();
    Queue::fake();
    $payload = [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['maya@example.com'],
        'subject' => 'Concurrent receipt',
        'text' => 'One message only.',
    ];

    $service = Mockery::mock(EmailSendService::class);
    $service->shouldReceive('send')
        ->once()
        ->andReturnUsing(function (Project $sentProject, ?Source $sentSource, array $sentPayload, ?string $key, ?string $hash) use ($workspace): never {
            Email::query()->create([
                'public_id' => 'email_race_winner',
                'idempotency_key' => $key,
                'idempotency_hash' => $hash,
                'workspace_id' => $workspace->id,
                'project_id' => $sentProject->id,
                'source_id' => $sentSource?->id,
                'environment' => $sentSource?->environment ?? 'prod',
                'status' => 'queued',
                'from_email' => 'receipts@example.com',
                'subject' => $sentPayload['subject'],
            ]);

            throw new QueryException(
                'sqlite',
                'insert into emails',
                [],
                new PDOException('UNIQUE constraint failed', 23000),
            );
        });
    $this->app->instance(EmailSendService::class, $service);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'concurrent-order')
        ->postJson('/api/emails', $payload)
        ->assertAccepted()
        ->assertJsonPath('id', 'email_race_winner')
        ->assertJsonPath('replayed', true);

    expect(Email::query()->count())->toBe(1)
        ->and($project)->toBeInstanceOf(Project::class)
        ->and($source)->toBeInstanceOf(Source::class);
});

it('clamps api pagination to a safe positive range', function () {
    [, , , $token] = larasendProjectFixture();

    $this->withToken($token)
        ->getJson('/api/emails?per_page=0')
        ->assertSuccessful()
        ->assertJsonPath('meta.per_page', 1);
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
        ->and($source->fresh()->last_quota['max_24_hour_send'])->toBe(50000)
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

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function sendRawEmail(Source $source, string $mime, array $destination = []): array
        {
            expect($mime)->toContain('Welcome to Larasend');

            return ['message_id' => 'ses-message-1', 'response' => ['MessageId' => 'ses-message-1']];
        }
    });

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

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

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

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

it('keeps non-ASCII suppression variants distinct while matching ASCII case and spaces', function () {
    [$workspace, $project, $source] = larasendProjectFixture();

    Queue::fake();

    Suppression::create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => ' ÄBC@Example.com ',
        'reason' => 'complaint',
        'event_type' => 'complaint',
    ]);

    $allowed = app(EmailSendService::class)->send($project, $source, [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Distinct <äBC@example.com>'],
        'subject' => 'Distinct non-ASCII recipient',
        'html' => '<h1>Hello</h1>',
        'text' => 'Hello',
    ]);

    expect($allowed->subject)->toBe('Distinct non-ASCII recipient')
        ->and(fn () => app(EmailSendService::class)->send($project, $source, [
            'from' => 'Larasend <receipts@example.com>',
            'to' => ['Blocked < ÄBC@EXAMPLE.COM >'],
            'subject' => 'ASCII case and space variant',
            'html' => '<h1>Hello</h1>',
            'text' => 'Hello',
        ]))->toThrow(ValidationException::class);
});

it('allows sends to expired suppressions but blocks active suppressions', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    Suppression::create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'expired@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
        'expires_at' => now()->subMinute(),
    ]);

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Expired <expired@example.com>'],
        'subject' => 'Expired suppression',
        'html' => '<h1>Hello</h1>',
        'text' => 'Hello',
    ])->assertAccepted();

    Suppression::create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'active@example.com',
        'reason' => 'complaint',
        'event_type' => 'complaint',
        'expires_at' => now()->addMinute(),
    ]);

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Active <active@example.com>'],
        'subject' => 'Active suppression',
        'html' => '<h1>Hello</h1>',
        'text' => 'Hello',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('to');

    expect(Email::query()->where('subject', 'Expired suppression')->exists())->toBeTrue()
        ->and(Email::query()->where('subject', 'Active suppression')->exists())->toBeFalse();
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

it('applies the ip limiter before api key authentication', function () {
    $this->withToken('invalid-key')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->getJson('/api/emails')
        ->assertUnauthorized()
        ->assertHeader('X-RateLimit-Limit', '180');
});

it('keys the pre-auth api limiter by the socket address', function () {
    $limiter = app(CacheRateLimiter::class)->limiter('larasend-api-ip');
    $request = HttpRequest::create('/api/emails', server: [
        'REMOTE_ADDR' => '198.51.100.77',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.24',
    ]);

    expect($limiter)->not->toBeNull()
        ->and($limiter($request)->key)->toBe('198.51.100.77')
        ->not->toBe('203.0.113.24');
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

it('rejects api keys for archived projects', function () {
    [, $project, , $plainText] = larasendProjectFixture();
    $project->forceFill(['archived_at' => now()])->save();

    $this->withToken($plainText)
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
