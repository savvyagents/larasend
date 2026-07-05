<?php

namespace App\Services;

use App\Events\EmailActivityUpdated;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Source;
use App\Models\Suppression;
use Carbon\CarbonImmutable;

class SesEventNormalizer
{
    public function __construct(private WebhookDeliveryService $webhookDeliveryService) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Source $source, array $payload): ?EmailEvent
    {
        $message = $payload['mail'] ?? [];
        $eventType = strtolower((string) ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown'));
        $sesMessageId = $message['messageId'] ?? null;
        $detail = $payload[$eventType] ?? $payload[ucfirst($eventType)] ?? [];
        $recipient = $this->recipient($eventType, $detail, $message);
        $email = $sesMessageId
            ? Email::query()->where('source_id', $source->id)->where('ses_message_id', $sesMessageId)->first()
            : null;

        $email?->forceFill(['status' => $this->statusFor($eventType)])->save();

        $event = EmailEvent::create([
            'email_id' => $email?->id,
            'source_id' => $source->id,
            'event_type' => $eventType,
            'ses_message_id' => $sesMessageId,
            'recipient' => $recipient,
            'url' => $detail['link'] ?? null,
            'user_agent' => $detail['userAgent'] ?? null,
            'ip_address' => $detail['ipAddress'] ?? null,
            'payload' => $payload,
            'occurred_at' => $this->occurredAt($message, $detail),
        ]);

        if ($email) {
            $this->recordSuppression($email, $eventType, $payload, $recipient);
            EmailActivityUpdated::dispatch($email->fresh());
        }

        $this->webhookDeliveryService->dispatchFor($event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $detail
     */
    private function occurredAt(array $message, array $detail): CarbonImmutable
    {
        return CarbonImmutable::parse($detail['timestamp'] ?? $message['timestamp'] ?? now());
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $message
     */
    private function recipient(string $eventType, array $detail, array $message): ?string
    {
        return match ($eventType) {
            'bounce' => $detail['bouncedRecipients'][0]['emailAddress'] ?? null,
            'complaint' => $detail['complainedRecipients'][0]['emailAddress'] ?? null,
            'delivery' => $detail['recipients'][0] ?? null,
            default => $message['destination'][0] ?? null,
        };
    }

    private function statusFor(string $eventType): string
    {
        return match ($eventType) {
            'delivery' => 'delivered',
            'open' => 'opened',
            'click' => 'clicked',
            'bounce' => 'bounced',
            'complaint' => 'complained',
            'reject' => 'rejected',
            'deliverydelay' => 'delayed',
            'renderingfailure' => 'failed',
            default => 'sent',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSuppression(Email $email, string $eventType, array $payload, ?string $recipient): void
    {
        if (! $recipient) {
            return;
        }

        $reason = match ($eventType) {
            'complaint' => 'complaint',
            'bounce' => $this->isPermanentBounce($payload) ? 'hard_bounce' : null,
            default => null,
        };

        if (! $reason) {
            return;
        }

        Suppression::query()->updateOrCreate(
            [
                'project_id' => $email->project_id,
                'email' => $recipient,
            ],
            [
                'workspace_id' => $email->workspace_id,
                'source_id' => $email->source_id,
                'email_id' => $email->id,
                'reason' => $reason,
                'event_type' => $eventType,
                'expires_at' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isPermanentBounce(array $payload): bool
    {
        $bounce = $payload['bounce'] ?? $payload['Bounce'] ?? [];
        $type = strtolower((string) ($bounce['bounceType'] ?? ''));

        return in_array($type, ['permanent', 'undetermined'], true);
    }
}
