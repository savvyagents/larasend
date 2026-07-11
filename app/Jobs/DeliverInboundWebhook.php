<?php

namespace App\Jobs;

use App\Models\InboundEmail;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fans an inbound email out to every active endpoint subscribed to the
 * "inbound.received" event, using the same signature scheme and delivery
 * records as outbound event webhooks.
 */
class DeliverInboundWebhook implements ShouldQueue
{
    use Queueable;

    public const EVENT_TYPE = 'inbound.received';

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(public int $inboundEmailId) {}

    public function handle(): void
    {
        $inbound = InboundEmail::query()->with(['project', 'thread'])->find($this->inboundEmailId);

        if (! $inbound || ! $inbound->project) {
            return;
        }

        $inbound->project
            ->webhookEndpoints()
            ->where('status', 'active')
            ->whereJsonContains('events', self::EVENT_TYPE)
            ->each(function (WebhookEndpoint $endpoint) use ($inbound): void {
                $this->deliver($endpoint, $inbound);
            });
    }

    private function deliver(WebhookEndpoint $endpoint, InboundEmail $inbound): void
    {
        $payload = $this->payload($inbound);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders($this->headers($endpoint, $inbound, $body))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $delivery = $this->recordDelivery(
                endpoint: $endpoint,
                payload: $payload,
                status: $response->successful() ? 'ok' : 'fail',
                httpStatus: $response->status(),
                latencyMs: $this->latencyMs($startedAt),
                responseBody: Str::limit($response->body(), 2000, ''),
            );

            if ($response->successful()) {
                $endpoint->forceFill(['last_delivered_at' => $delivery->delivered_at])->save();
            }
        } catch (ConnectionException $exception) {
            $this->recordDelivery(
                endpoint: $endpoint,
                payload: $payload,
                status: 'fail',
                httpStatus: null,
                latencyMs: $this->latencyMs($startedAt),
                responseBody: Str::limit($exception->getMessage(), 2000, ''),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(InboundEmail $inbound): array
    {
        return [
            'id' => 'evt_inbound_'.$inbound->id,
            'type' => self::EVENT_TYPE,
            'created_at' => $inbound->received_at->toIso8601String(),
            'data' => [
                'inbound_email' => [
                    'id' => $inbound->public_id,
                    'from' => trim(($inbound->from_name ? $inbound->from_name.' ' : '').'<'.$inbound->from_email.'>'),
                    'to' => $inbound->to_email,
                    'subject' => $inbound->subject,
                    'text' => $inbound->text,
                    'html' => $inbound->html,
                    'headers' => $inbound->headers,
                    'attachments' => $inbound->attachments,
                    'message_id' => $inbound->message_id,
                    'in_reply_to' => $inbound->in_reply_to,
                    'thread_id' => $inbound->thread?->public_id,
                    'received_at' => $inbound->received_at->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(WebhookEndpoint $endpoint, InboundEmail $inbound, string $body): array
    {
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->signing_secret);

        return [
            'Larasend-Event-Id' => 'evt_inbound_'.$inbound->id,
            'Larasend-Event-Type' => self::EVENT_TYPE,
            'Larasend-Signature' => "t={$timestamp},v1={$signature}",
            'User-Agent' => 'Larasend-Webhooks/1.0',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordDelivery(
        WebhookEndpoint $endpoint,
        array $payload,
        string $status,
        ?int $httpStatus,
        int $latencyMs,
        string $responseBody,
    ): WebhookDelivery {
        return WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'public_id' => 'whd_'.Str::lower(Str::random(16)),
            'event_type' => self::EVENT_TYPE,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'payload' => $payload,
            'response_body' => $responseBody,
            'delivered_at' => now(),
        ]);
    }

    private function latencyMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
