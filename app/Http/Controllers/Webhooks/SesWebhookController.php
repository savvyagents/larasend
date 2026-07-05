<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\WebhookLog;
use App\Services\SesEventNormalizer;
use App\Services\SnsSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

        if ($messageType === 'SubscriptionConfirmation' && isset($payload['SubscribeURL'])) {
            $subscribeUrl = (string) $payload['SubscribeURL'];

            if (! $this->isAllowedSnsUrl($subscribeUrl, $source->ses_region)) {
                $log->forceFill(['status' => 'rejected', 'error' => 'Unexpected SNS SubscribeURL host.'])->save();

                return response()->json(['message' => 'Unexpected SNS SubscribeURL host.'], 422);
            }

            Http::timeout(10)->get($subscribeUrl)->throw();
            $log->forceFill(['status' => 'confirmed'])->save();

            return response()->json(['message' => 'SNS subscription confirmed.']);
        }

        $message = $payload['Message'] ?? null;
        $eventPayload = is_string($message) ? json_decode($message, true) : $payload;

        if (! is_array($eventPayload)) {
            $log->forceFill(['status' => 'rejected', 'error' => 'Invalid SNS message payload.'])->save();

            return response()->json(['message' => 'Invalid event payload.'], 422);
        }

        $normalizer->record($source, $eventPayload);
        $log->forceFill(['status' => 'processed'])->save();

        return response()->json(['message' => 'Event processed.']);
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
