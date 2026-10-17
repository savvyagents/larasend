<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\WebhookLog;
use App\Services\SesEventNormalizer;
use App\Services\SnsSignatureVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SesWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, SesEventNormalizer $normalizer, SnsSignatureVerifier $signatureVerifier): JsonResponse
    {
        $source = Source::query()->where('webhook_token', $token)->firstOrFail();
        $payload = $request->json()->all();
        $messageType = $payload['Type'] ?? null;

        $log = WebhookLog::create([
            'source_id' => $source->id,
            'provider' => 'ses',
            'message_type' => $messageType,
            'status' => 'received',
            'payload' => $payload,
        ]);

        if (! $signatureVerifier->verify($payload, $source->ses_region)) {
            $log->forceFill(['status' => 'rejected', 'error' => 'Invalid or missing SNS message signature.'])->save();

            return response()->json(['message' => 'Invalid SNS message signature.'], 422);
        }

        if (! $this->hasFreshTimestamp($payload)) {
            $log->forceFill(['status' => 'rejected', 'error' => 'Stale or invalid SNS message timestamp.'])->save();

            return response()->json(['message' => 'Stale or invalid SNS message timestamp.'], 422);
        }

        $topicArn = is_string($payload['TopicArn'] ?? null) ? $payload['TopicArn'] : null;

        if ($source->sns_topic_arn !== null && $topicArn !== $source->sns_topic_arn) {
            $log->forceFill(['status' => 'rejected', 'error' => 'Unexpected SNS TopicArn.'])->save();

            return response()->json(['message' => 'Unexpected SNS TopicArn.'], 422);
        }

        if ($messageType === 'SubscriptionConfirmation' && isset($payload['SubscribeURL'])) {
            $subscribeUrl = (string) $payload['SubscribeURL'];

            if (! $this->isAllowedSnsUrl($subscribeUrl, $source->ses_region)) {
                $log->forceFill(['status' => 'rejected', 'error' => 'Unexpected SNS SubscribeURL host.'])->save();

                return response()->json(['message' => 'Unexpected SNS SubscribeURL host.'], 422);
            }

            Http::timeout(10)->get($subscribeUrl)->throw();

            // Pin the topic on the first confirmation so later envelopes signed
            // by a different (attacker-owned) topic are rejected above.
            if ($source->sns_topic_arn === null && $topicArn !== null) {
                $source->forceFill(['sns_topic_arn' => $topicArn])->save();
            }

            $log->forceFill(['status' => 'confirmed'])->save();

            return response()->json(['message' => 'SNS subscription confirmed.']);
        }

        $message = $payload['Message'] ?? null;
        $eventPayload = is_string($message) ? json_decode($message, true) : $payload;

        if (! is_array($eventPayload)) {
            $log->forceFill(['status' => 'rejected', 'error' => 'Invalid SNS message payload.'])->save();

            return response()->json(['message' => 'Invalid event payload.'], 422);
        }

        $normalizer->record($source, $eventPayload, is_string($payload['MessageId'] ?? null) ? $payload['MessageId'] : null);
        $log->forceFill(['status' => 'processed'])->save();

        return response()->json(['message' => 'Event processed.']);
    }

    /**
     * Reject replayed envelopes: AWS delivers SNS messages promptly, so a
     * Timestamp older than 15 minutes (or implausibly far in the future)
     * indicates a captured envelope being replayed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hasFreshTimestamp(array $payload): bool
    {
        $timestamp = $payload['Timestamp'] ?? null;

        if (! is_string($timestamp)) {
            return false;
        }

        try {
            $sentAt = CarbonImmutable::parse($timestamp);
        } catch (Throwable) {
            return false;
        }

        return $sentAt->greaterThanOrEqualTo(now()->subMinutes(15))
            && $sentAt->lessThanOrEqualTo(now()->addMinutes(5));
    }

    private function isAllowedSnsUrl(string $url, string $region): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $scheme === 'https'
            && is_string($host)
            && Str::lower($host) === "sns.{$region}.amazonaws.com";
    }
}
