<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\SourceProvider;
use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\WebhookLog;
use App\Services\InboundEmailIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CloudflareInboundController extends Controller
{
    /**
     * Raw messages above this size are rejected. Mirrors what a small
     * self-hosted install can comfortably buffer in one request.
     */
    private const MAX_MESSAGE_BYTES = 26_214_400; // 25 MiB

    public function __invoke(Request $request, string $token, InboundEmailIngestor $ingestor): JsonResponse
    {
        $source = Source::query()
            ->where('webhook_token', $token)
            ->where('provider', SourceProvider::Cloudflare)
            ->firstOrFail();

        $validated = $request->validate([
            'from' => ['required', 'string', 'max:320'],
            'to' => ['required', 'string', 'max:320'],
            'raw' => ['required', 'string'],
        ]);

        $log = WebhookLog::create([
            'source_id' => $source->id,
            'provider' => 'cloudflare',
            'message_type' => 'inbound_email',
            'status' => 'received',
            'payload' => [
                'from' => $validated['from'],
                'to' => $validated['to'],
                'raw_bytes' => (int) (strlen($validated['raw']) * 3 / 4),
            ],
        ]);

        $mime = base64_decode($validated['raw'], strict: true);

        if ($mime === false || $mime === '') {
            $log->forceFill(['status' => 'rejected', 'error' => 'Raw message is not valid base64.'])->save();

            return response()->json(['message' => 'Raw message is not valid base64.'], 422);
        }

        if (strlen($mime) > self::MAX_MESSAGE_BYTES) {
            $log->forceFill(['status' => 'rejected', 'error' => 'Message exceeds the maximum accepted size.'])->save();

            return response()->json(['message' => 'Message too large.'], 413);
        }

        try {
            $inbound = $ingestor->ingest($source, $validated['from'], $validated['to'], $mime);
        } catch (Throwable $exception) {
            report($exception);
            $log->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();

            // A 5xx makes the Worker throw, which defers the message so the
            // sending server retries instead of the email being lost.
            return response()->json(['message' => 'Ingestion failed.'], 500);
        }

        $log->forceFill(['status' => 'processed'])->save();

        return response()->json(['id' => $inbound->public_id], 202);
    }
}
