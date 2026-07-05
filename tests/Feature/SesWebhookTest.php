<?php

use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebhookLog;
use App\Models\Workspace;
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
