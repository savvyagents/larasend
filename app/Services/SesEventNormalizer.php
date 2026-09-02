<?php

namespace App\Services;

use App\Events\EmailActivityUpdated;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Source;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class SesEventNormalizer
{
    public function __construct(private WebhookDeliveryService $webhookDeliveryService) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Source $source, array $payload, ?string $providerMessageId = null): ?EmailEvent
    {
        $message = $payload['mail'] ?? [];
        $eventType = strtolower((string) ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown'));
        $sesMessageId = $message['messageId'] ?? null;
        $detail = $payload[$eventType] ?? $payload[ucfirst($eventType)] ?? [];
        $recipient = $this->recipient($eventType, $detail, $message);
        $emailMatches = $sesMessageId
            ? Email::query()->with('source')->where('ses_message_id', $sesMessageId)->limit(2)->get()
            : collect();
        $email = $emailMatches->count() === 1 ? $emailMatches->first() : null;
        $source = $email?->source ?? $source;
        $providerEventId = hash('sha256', $source->id.'|'.($providerMessageId ?: json_encode($payload, JSON_THROW_ON_ERROR)));
        $existing = EmailEvent::query()->where('provider_event_id', $providerEventId)->first();

        if ($existing) {
            if ($existing->email) {
                EmailActivityUpdated::dispatch($existing->email->fresh());
            }

            $this->webhookDeliveryService->dispatchFor($existing);

            return $existing;
        }

        $nextStatus = $this->statusFor($eventType);

        $event = DB::transaction(function () use ($email, $source, $eventType, $sesMessageId, $providerEventId, $recipient, $detail, $payload, $message, $nextStatus): EmailEvent {
            $lockedEmail = $email
                ? Email::query()->whereKey($email->id)->lockForUpdate()->first()
                : null;

            if ($lockedEmail && $this->statusPriority($nextStatus) >= $this->statusPriority($lockedEmail->status)) {
                $lockedEmail->forceFill(['status' => $nextStatus])->save();
            }

            $event = EmailEvent::create([
                'email_id' => $lockedEmail?->id,
                'source_id' => $source->id,
                'event_type' => $eventType,
                'ses_message_id' => $sesMessageId,
                'provider_event_id' => $providerEventId,
                'recipient' => $recipient,
                'url' => $detail['link'] ?? null,
                'user_agent' => $detail['userAgent'] ?? null,
                'ip_address' => $detail['ipAddress'] ?? null,
                'payload' => $payload,
                'occurred_at' => $this->occurredAt($message, $detail),
            ]);

            if ($lockedEmail) {
                $this->recordSuppression($lockedEmail, $eventType, $payload, $recipient);
            }

            return $event;
        });

        if ($event->email) {
            EmailActivityUpdated::dispatch($event->email->fresh());
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

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'queued' => 0,
            'sending' => 1,
            'sent', 'delayed' => 2,
            'delivered' => 3,
            'opened' => 4,
            'clicked' => 5,
            'bounced', 'rejected', 'failed' => 6,
            'complained' => 7,
            default => 0,
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

        $attributes = (new Suppression)->forceFill([
            'project_id' => $email->project_id,
            'email' => Suppression::normalizeEmail($recipient),
            'workspace_id' => $email->workspace_id,
            'source_id' => $email->source_id,
            'email_id' => $email->id,
            'reason' => $reason,
            'event_type' => $eventType,
            'expires_at' => null,
        ])->getAttributes();

        $connection = (new Suppression)->getConnection();

        if ($connection->getDriverName() === 'sqlsrv') {
            $this->upsertSqlServerSuppression($connection, $attributes);

            return;
        }

        Suppression::query()->upsert(
            [$attributes],
            ['project_id', 'email'],
            ['workspace_id', 'source_id', 'email_id', 'reason', 'event_type', 'expires_at'],
        );
    }

    /**
     * SQL Server's MERGE must match the generated binary key rather than the
     * case-insensitive raw email column. HOLDLOCK keeps the match-and-insert
     * decision atomic with concurrent webhook deliveries.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertSqlServerSuppression(ConnectionInterface $connection, array $attributes): void
    {
        $timestamp = now();
        $values = [
            $attributes['workspace_id'],
            $attributes['project_id'],
            $attributes['source_id'],
            $attributes['email_id'],
            $attributes['email'],
            Suppression::normalizeEmail($attributes['email']),
            $attributes['reason'],
            $attributes['event_type'],
            $attributes['expires_at'],
            $timestamp,
            $timestamp,
        ];

        $connection->statement(
            <<<'SQL'
                MERGE INTO [suppressions] WITH (HOLDLOCK) AS [target]
                USING (VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)) AS [source]
                    ([workspace_id], [project_id], [source_id], [email_id], [email], [email_normalized], [reason], [event_type], [expires_at], [created_at], [updated_at])
                ON [target].[project_id] = [source].[project_id]
                    AND [target].[email_normalized] = [source].[email_normalized] COLLATE Latin1_General_100_BIN2
                WHEN MATCHED THEN
                    UPDATE SET
                        [target].[workspace_id] = [source].[workspace_id],
                        [target].[source_id] = [source].[source_id],
                        [target].[email_id] = [source].[email_id],
                        [target].[email] = [source].[email],
                        [target].[reason] = [source].[reason],
                        [target].[event_type] = [source].[event_type],
                        [target].[expires_at] = [source].[expires_at],
                        [target].[updated_at] = [source].[updated_at]
                WHEN NOT MATCHED THEN
                    INSERT ([workspace_id], [project_id], [source_id], [email_id], [email], [reason], [event_type], [expires_at], [created_at], [updated_at])
                    VALUES ([source].[workspace_id], [source].[project_id], [source].[source_id], [source].[email_id], [source].[email], [source].[reason], [source].[event_type], [source].[expires_at], [source].[created_at], [source].[updated_at]);
                SQL,
            $values,
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
