<?php

use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function outboundWebhookFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'outbound-acme']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Billing', 'slug' => 'billing']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Prod', 'webhook_token' => 'ses-outbound-token']);
    $email = Email::create([
        'public_id' => 'email_outbound',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'sent',
        'ses_message_id' => 'ses-outbound-1',
        'from_email' => 'receipts@example.com',
        'from_name' => 'Acme Receipts',
        'subject' => 'Receipt',
    ]);
    $email->recipients()->create(['type' => 'to', 'email' => 'maya@example.com', 'name' => 'Maya']);

    return [$project, $source, $email];
}

it('delivers normalized ses events to active matching webhook endpoints', function () {
    [$project, $source, $email] = outboundWebhookFixture();

    $issued = WebhookEndpoint::issue($project, 'https://example.com/webhooks/larasend', ['delivery', 'bounce']);
    $endpoint = $issued['endpoint'];

    Http::fake([
        'https://example.com/webhooks/larasend' => Http::response(['ok' => true], 202),
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $message = [
        'eventType' => 'Delivery',
        'mail' => [
            'messageId' => 'ses-outbound-1',
            'timestamp' => now()->toIso8601String(),
            'destination' => ['maya@example.com'],
        ],
        'delivery' => [
            'recipients' => ['maya@example.com'],
            'timestamp' => now()->toIso8601String(),
        ],
    ];

    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($message)]),
    )->assertSuccessful();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($email->fresh()->status)->toBe('delivered')
        ->and($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->event_type)->toBe('delivery')
        ->and($delivery->status)->toBe('ok')
        ->and($delivery->http_status)->toBe(202)
        ->and($delivery->payload['type'])->toBe('delivery')
        ->and($delivery->payload['data']['email']['id'])->toBe('email_outbound')
        ->and($endpoint->fresh()->last_delivered_at)->not->toBeNull();

    Http::assertSent(function ($request) use ($issued): bool {
        $signature = $request->header('Larasend-Signature')[0] ?? '';
        $timestamp = str($signature)->between('t=', ',v1=')->toString();
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), $issued['plain_text']);

        return $request->url() === 'https://example.com/webhooks/larasend'
            && $request->hasHeader('Larasend-Event-Type', 'delivery')
            && str_contains($signature, "v1={$expected}")
            && $request['data']['email']['id'] === 'email_outbound';
    });
});

it('skips paused endpoints and endpoints that are not subscribed to the event', function () {
    [$project, $source] = outboundWebhookFixture();

    WebhookEndpoint::issue($project, 'https://example.com/webhooks/paused', ['delivery'], 'paused');
    WebhookEndpoint::issue($project, 'https://example.com/webhooks/opened', ['open']);

    Http::fake([
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $message = [
        'eventType' => 'Delivery',
        'mail' => [
            'messageId' => 'ses-outbound-1',
            'timestamp' => now()->toIso8601String(),
            'destination' => ['maya@example.com'],
        ],
        'delivery' => [
            'recipients' => ['maya@example.com'],
            'timestamp' => now()->toIso8601String(),
        ],
    ];

    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($message)]),
    )->assertSuccessful();

    expect(WebhookDelivery::query()->count())->toBe(0);

    Http::assertNotSent(fn ($request) => $request->url() !== SES_TEST_SIGNING_CERT_URL);
});

it('logs failed outbound webhook attempts without rejecting the ses webhook', function () {
    [$project, $source] = outboundWebhookFixture();

    WebhookEndpoint::issue($project, 'https://example.com/webhooks/failing', ['delivery']);

    Http::fake([
        'https://example.com/webhooks/failing' => Http::response('unavailable', 503),
        SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate()),
    ]);

    $message = [
        'eventType' => 'Delivery',
        'mail' => [
            'messageId' => 'ses-outbound-1',
            'timestamp' => now()->toIso8601String(),
            'destination' => ['maya@example.com'],
        ],
        'delivery' => [
            'recipients' => ['maya@example.com'],
            'timestamp' => now()->toIso8601String(),
        ],
    ];

    $this->postJson(
        "/api/webhooks/ses/{$source->webhook_token}",
        sesSignedSnsEnvelope('Notification', ['Message' => json_encode($message)]),
    )->assertSuccessful();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe('fail')
        ->and($delivery->http_status)->toBe(503)
        ->and($delivery->response_body)->toBe('unavailable');
});
