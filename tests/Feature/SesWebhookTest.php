<?php

use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\SesEventNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function sesWebhookFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'webhook-acme']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Project', 'slug' => 'project']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Prod', 'webhook_token' => 'ses-token']);
    $email = Email::create([
        'public_id' => 'email_webhook',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'sent',
        'ses_message_id' => 'ses-1',
        'from_email' => 'receipts@example.com',
        'subject' => 'Receipt',
    ]);

    return [$source, $email];
}

it('confirms sns subscriptions', function () {
    [$source] = sesWebhookFixture();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
        'https://sns.us-east-1.amazonaws.com/confirm' => Http::response('ok'),
    ]);

    $envelope = sesSignedSnsEnvelope('SubscriptionConfirmation', [
        'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/confirm',
    ]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertSuccessful();

    expect(WebhookLog::query()->where('status', 'confirmed')->exists())->toBeTrue();
});

it('rejects sns subscription confirmations from unexpected hosts', function () {
    [$source] = sesWebhookFixture();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $envelope = sesSignedSnsEnvelope('SubscriptionConfirmation', [
        'SubscribeURL' => 'https://example.com/confirm',
    ]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertUnprocessable();

    Http::assertNotSent(fn ($request) => $request->url() === 'https://example.com/confirm');

    expect(WebhookLog::query()->where('status', 'rejected')->exists())->toBeTrue();
});

it('pins the topic arn from the first sns subscription confirmation', function () {
    [$source] = sesWebhookFixture();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
        'https://sns.us-east-1.amazonaws.com/confirm' => Http::response('ok'),
    ]);

    $envelope = sesSignedSnsEnvelope('SubscriptionConfirmation', [
        'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/confirm',
    ]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertSuccessful();

    expect($source->fresh()->sns_topic_arn)->toBe('arn:aws:sns:us-east-1:123456789012:larasend-test');
});

it('rejects subscription confirmations for a different topic than the pinned one', function () {
    [$source] = sesWebhookFixture();
    $source->forceFill(['sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:larasend-test'])->save();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
        'https://sns.us-east-1.amazonaws.com/confirm' => Http::response('ok'),
    ]);

    $envelope = sesSignedSnsEnvelope('SubscriptionConfirmation', [
        'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/confirm',
        'TopicArn' => 'arn:aws:sns:us-east-1:999999999999:attacker-topic',
    ]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertUnprocessable();

    Http::assertNotSent(fn ($request) => $request->url() === 'https://sns.us-east-1.amazonaws.com/confirm');

    expect($source->fresh()->sns_topic_arn)->toBe('arn:aws:sns:us-east-1:123456789012:larasend-test')
        ->and(WebhookLog::query()->where('status', 'rejected')->exists())->toBeTrue();
});

it('rejects validly signed notifications from a different sns topic', function () {
    [$source, $email] = sesWebhookFixture();
    $source->forceFill(['sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:larasend-test'])->save();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $message = [
        'eventType' => 'Delivery',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'delivery' => ['recipients' => ['maya@example.com'], 'timestamp' => now()->toIso8601String()],
    ];

    $envelope = sesSignedSnsEnvelope('Notification', [
        'Message' => json_encode($message),
        'TopicArn' => 'arn:aws:sns:us-east-1:999999999999:attacker-topic',
    ]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertUnprocessable();

    expect($email->fresh()->status)->toBe('sent')
        ->and(WebhookLog::query()->where('status', 'rejected')->exists())->toBeTrue();
});

it('rejects sns envelopes with stale or far-future timestamps', function (string $timestamp) {
    [$source, $email] = sesWebhookFixture();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $message = [
        'eventType' => 'Delivery',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'delivery' => ['recipients' => ['maya@example.com'], 'timestamp' => now()->toIso8601String()],
    ];

    $envelope = sesSignedSnsEnvelope('Notification', [
        'Message' => json_encode($message),
        'Timestamp' => $timestamp,
    ]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertUnprocessable();

    expect($email->fresh()->status)->toBe('sent')
        ->and(WebhookLog::query()->where('status', 'rejected')->exists())->toBeTrue();
})->with([
    'replayed envelope' => fn () => now()->subMinutes(16)->toIso8601String(),
    'far-future envelope' => fn () => now()->addMinutes(6)->toIso8601String(),
    'unparseable timestamp' => 'not-a-timestamp',
]);

it('rejects ses events with no valid sns signature', function () {
    [$source, $email] = sesWebhookFixture();

    Http::fake();

    $message = [
        'eventType' => 'Delivery',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'delivery' => ['recipients' => ['maya@example.com'], 'timestamp' => now()->toIso8601String()],
    ];

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", [
        'Type' => 'Notification',
        'Message' => json_encode($message),
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:larasend-test',
        'Timestamp' => now()->toIso8601String(),
        'SignatureVersion' => '1',
        'SigningCertURL' => SES_TEST_SIGNING_CERT_URL,
        'Signature' => base64_encode('forged-signature-not-signed-by-aws'),
    ])->assertUnprocessable();

    expect($email->fresh()->status)->toBe('sent')
        ->and(WebhookLog::query()->where('status', 'rejected')->exists())->toBeTrue();
});

it('rejects ses events signed with an untrusted certificate host', function () {
    [$source, $email] = sesWebhookFixture();

    Http::fake([
        'https://attacker.example/cert.pem' => Http::response(sesTestPublicCertificate()),
    ]);

    $envelope = sesSignedSnsEnvelope('Notification', [
        'Message' => json_encode(['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-1']]),
    ]);
    $envelope['SigningCertURL'] = 'https://attacker.example/cert.pem';

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertUnprocessable();

    expect($email->fresh()->status)->toBe('sent');
});

it('normalizes ses delivery events', function () {
    [$source, $email] = sesWebhookFixture();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $message = [
        'eventType' => 'Delivery',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'delivery' => ['recipients' => ['maya@example.com'], 'timestamp' => now()->toIso8601String()],
    ];

    $envelope = sesSignedSnsEnvelope('Notification', ['Message' => json_encode($message)]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertSuccessful();

    expect($email->fresh()->status)->toBe('delivered')
        ->and($email->events()->where('event_type', 'delivery')->exists())->toBeTrue();
});

it('deduplicates repeated sns notifications by message id', function () {
    [$source, $email] = sesWebhookFixture();
    Http::fake([SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate())]);
    $message = [
        'eventType' => 'Delivery',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'delivery' => ['recipients' => ['maya@example.com'], 'timestamp' => now()->toIso8601String()],
    ];
    $envelope = sesSignedSnsEnvelope('Notification', ['Message' => json_encode($message)]);

    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertSuccessful();
    $this->postJson("/api/webhooks/ses/{$source->webhook_token}", $envelope)->assertSuccessful();

    expect($email->events()->where('event_type', 'delivery')->count())->toBe(1);
});

it('does not regress an advanced email status when an older event arrives late', function () {
    [$source, $email] = sesWebhookFixture();
    Http::fake([SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate())]);
    $click = [
        'eventType' => 'Click',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'click' => ['link' => 'https://example.com', 'timestamp' => now()->toIso8601String()],
    ];
    $delivery = [
        'eventType' => 'Delivery',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->subMinute()->toIso8601String(), 'destination' => ['maya@example.com']],
        'delivery' => ['recipients' => ['maya@example.com'], 'timestamp' => now()->subMinute()->toIso8601String()],
    ];

    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($click)]),
    )->assertSuccessful();
    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($delivery)]),
    )->assertSuccessful();

    expect($email->fresh()->status)->toBe('clicked')
        ->and($email->events()->pluck('event_type')->all())->toContain('click', 'delivery');
});

it('reloads and locks the email before advancing its ses status', function () {
    [$source, $email] = sesWebhookFixture();
    $retrievedEvent = 'eloquent.retrieved: '.Email::class;
    $advancedAfterLookup = false;

    Event::listen($retrievedEvent, function (Email $retrievedEmail) use ($email, &$advancedAfterLookup): void {
        if ($advancedAfterLookup || $retrievedEmail->id !== $email->id) {
            return;
        }

        $advancedAfterLookup = true;
        DB::table('emails')->where('id', $email->id)->update([
            'status' => 'clicked',
            'updated_at' => now(),
        ]);
    });

    try {
        app(SesEventNormalizer::class)->record($source, [
            'eventType' => 'Delivery',
            'mail' => [
                'messageId' => 'ses-1',
                'timestamp' => now()->toIso8601String(),
                'destination' => ['maya@example.com'],
            ],
            'delivery' => [
                'recipients' => ['maya@example.com'],
                'timestamp' => now()->toIso8601String(),
            ],
        ], 'sns-stale-email-model');
    } finally {
        Event::forget($retrievedEvent);
    }

    expect($advancedAfterLookup)->toBeTrue()
        ->and($email->fresh()->status)->toBe('clicked')
        ->and($email->events()->where('event_type', 'delivery')->exists())->toBeTrue()
        ->and(file_get_contents(app_path('Services/SesEventNormalizer.php')))->toContain('->lockForUpdate()');
});

it('records suppressions for permanent ses bounces and complaints', function () {
    [$source, $email] = sesWebhookFixture();

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $bounce = [
        'eventType' => 'Bounce',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['maya@example.com']],
        'bounce' => [
            'bounceType' => 'Permanent',
            'timestamp' => now()->toIso8601String(),
            'bouncedRecipients' => [
                ['emailAddress' => 'maya@example.com', 'status' => '550', 'diagnosticCode' => 'No such user'],
            ],
        ],
    ];

    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($bounce)]),
    )->assertSuccessful();

    $complaint = [
        'eventType' => 'Complaint',
        'mail' => ['messageId' => 'ses-1', 'timestamp' => now()->toIso8601String(), 'destination' => ['abuse@example.com']],
        'complaint' => [
            'timestamp' => now()->toIso8601String(),
            'complainedRecipients' => [
                ['emailAddress' => 'abuse@example.com'],
            ],
        ],
    ];

    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($complaint)]),
    )->assertSuccessful();

    expect(Suppression::query()->where('email', 'maya@example.com')->where('reason', 'hard_bounce')->exists())->toBeTrue()
        ->and(Suppression::query()->where('email', 'abuse@example.com')->where('reason', 'complaint')->exists())->toBeTrue()
        ->and($email->fresh()->status)->toBe('complained');
});

it('atomically replaces Cloudflare ownership when recording an SES complaint', function () {
    [$source, $email] = sesWebhookFixture();
    $cloudflareSource = Source::create([
        'project_id' => $email->project_id,
        'name' => 'Cloudflare',
        'environment' => 'staging',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'token',
        'cloudflare_account_id' => 'account',
        'webhook_token' => 'cloudflare-webhook-token',
    ]);
    $suppression = Suppression::create([
        'workspace_id' => $email->workspace_id,
        'project_id' => $email->project_id,
        'source_id' => $cloudflareSource->id,
        'email' => 'abuse@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
        'expires_at' => now()->addDay(),
    ]);
    $suppression->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ])->saveQuietly();
    $originalCreatedAt = $suppression->created_at;
    $payload = [
        'eventType' => 'Complaint',
        'mail' => [
            'messageId' => 'ses-1',
            'timestamp' => now()->toIso8601String(),
            'destination' => ['Abuse@Example.com'],
        ],
        'complaint' => [
            'timestamp' => now()->toIso8601String(),
            'complainedRecipients' => [
                ['emailAddress' => 'Abuse@Example.com'],
            ],
        ],
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(SesEventNormalizer::class)->record($source, $payload);

    $suppressionQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'suppressions'))
        ->values();
    DB::disableQueryLog();

    expect($suppression->fresh())
        ->id->toBe($suppression->id)
        ->workspace_id->toBe($email->workspace_id)
        ->project_id->toBe($email->project_id)
        ->source_id->toBe($source->id)
        ->email_id->toBe($email->id)
        ->email->toBe('abuse@example.com')
        ->reason->toBe('complaint')
        ->event_type->toBe('complaint')
        ->expires_at->toBeNull()
        ->created_at->toEqual($originalCreatedAt)
        ->updated_at->toBeGreaterThan($originalCreatedAt)
        ->and($suppressionQueries)->toHaveCount(1)
        ->and($suppressionQueries->sole())->toMatch('/on conflict|on duplicate key update/i');
});
