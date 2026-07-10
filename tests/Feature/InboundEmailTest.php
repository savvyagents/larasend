<?php

use App\Jobs\DeliverInboundWebhook;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function inboundProjectFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Inbound Co', 'slug' => 'inbound-co']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'postbox', 'slug' => 'postbox']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-inbound',
        'default_from_email' => 'notifications@mail.example.com',
        'webhook_token' => 'inbound-token-'.str()->random(8),
    ]);

    return [$user, $workspace, $project, $source];
}

function sampleInboundMime(): string
{
    return implode("\r\n", [
        'From: Maya Lin <maya@customer.test>',
        'To: support@example.com',
        'Subject: Need help with my invoice',
        'Message-ID: <origin-123@customer.test>',
        'In-Reply-To: <thread-99@example.com>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="BOUND"',
        '',
        '--BOUND',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Hi, my invoice looks wrong.',
        '--BOUND',
        'Content-Type: text/html; charset=utf-8',
        '',
        '<p>Hi, my <strong>invoice</strong> looks wrong.</p>',
        '--BOUND--',
        '',
    ]);
}

it('ingests inbound email posted by the cloudflare worker', function () {
    [, $workspace, $project, $source] = inboundProjectFixture();

    Queue::fake();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertStatus(202);

    $inbound = InboundEmail::query()->firstOrFail();

    expect($inbound->project_id)->toBe($project->id)
        ->and($inbound->from_email)->toBe('maya@customer.test')
        ->and($inbound->from_name)->toBe('Maya Lin')
        ->and($inbound->to_email)->toBe('support@example.com')
        ->and($inbound->subject)->toBe('Need help with my invoice')
        ->and(trim((string) $inbound->text))->toBe('Hi, my invoice looks wrong.')
        ->and($inbound->html)->toContain('<strong>invoice</strong>')
        ->and($inbound->message_id)->toBe('origin-123@customer.test')
        ->and($inbound->in_reply_to)->toBe('thread-99@example.com')
        ->and($inbound->mime_size)->toBeGreaterThan(0);

    Queue::assertPushed(DeliverInboundWebhook::class);
});

it('rejects inbound posts with an unknown token or wrong provider', function () {
    [, , , $source] = inboundProjectFixture();
    $source->forceFill(['provider' => 'ses'])->save();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'a@b.test',
        'to' => 'c@d.test',
        'raw' => base64_encode('hello'),
    ])->assertNotFound();

    $this->postJson('/api/webhooks/inbound/cloudflare/not-a-token', [
        'from' => 'a@b.test',
        'to' => 'c@d.test',
        'raw' => base64_encode('hello'),
    ])->assertNotFound();

    expect(InboundEmail::query()->count())->toBe(0);
});

it('rejects invalid base64 payloads', function () {
    [, , , $source] = inboundProjectFixture();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'a@b.test',
        'to' => 'c@d.test',
        'raw' => 'not!!valid@@base64',
    ])->assertStatus(422);
});

it('fans inbound emails out to subscribed webhook endpoints with signatures', function () {
    [, , $project, $source] = inboundProjectFixture();

    $issued = WebhookEndpoint::issue($project, 'https://customer.test/hooks', ['inbound.received']);
    WebhookEndpoint::issue($project, 'https://customer.test/other', ['delivery']);

    Http::fake(['https://customer.test/*' => Http::response(['ok' => true])]);

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertStatus(202);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://customer.test/hooks'
            && $request->hasHeader('Larasend-Event-Type', 'inbound.received')
            && str_contains($request->header('Larasend-Signature')[0] ?? '', 'v1=')
            && ($request->data()['data']['inbound_email']['subject'] ?? null) === 'Need help with my invoice';
    });

    expect($issued['endpoint']->fresh()->last_delivered_at)->not->toBeNull();
});
