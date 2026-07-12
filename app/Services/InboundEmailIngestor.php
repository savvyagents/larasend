<?php

namespace App\Services;

use App\Jobs\DeliverInboundWebhook;
use App\Models\InboundEmail;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Provider-agnostic inbound pipeline: every receiving adapter (Cloudflare
 * Worker today, SES/S3 or SMTP later) delivers an envelope plus raw MIME
 * here, and everything downstream — storage, parsing, webhook fan-out —
 * is shared.
 */
class InboundEmailIngestor
{
    public function __construct(
        private MailMimeParser $parser,
        private ThreadResolver $threads,
    ) {}

    public function ingest(Source $source, string $envelopeFrom, string $envelopeTo, string $mime): InboundEmail
    {
        $project = $source->project;
        $publicId = 'inbound_'.Str::random(24);

        $mimePath = "inbound/{$project->id}/{$publicId}.eml";
        Storage::disk('local')->put($mimePath, $mime);

        $message = $this->parser->parse($mime, autoClose: true);

        $inbound = InboundEmail::create([
            'public_id' => $publicId,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'source_id' => $source->id,
            'from_email' => $this->headerAddress($message) ?? $envelopeFrom,
            'from_name' => $message->getHeader(HeaderConsts::FROM)?->getPersonName() ?: null,
            'to_email' => $envelopeTo,
            'subject' => $message->getSubject(),
            'text' => $message->getTextContent(),
            'html' => $message->getHtmlContent(),
            'headers' => $this->interestingHeaders($message),
            'attachments' => $this->attachmentMetadata($message),
            'message_id' => trim((string) $message->getHeaderValue(HeaderConsts::MESSAGE_ID), '<>') ?: null,
            'in_reply_to' => trim((string) $message->getHeaderValue(HeaderConsts::IN_REPLY_TO), '<>') ?: null,
            'mime_disk' => 'local',
            'mime_path' => $mimePath,
            'mime_size' => strlen($mime),
            'received_at' => now(),
        ]);

        $this->threads->attachInbound($inbound);

        DeliverInboundWebhook::dispatch($inbound->id)->onQueue('webhooks');

        return $inbound;
    }

    private function headerAddress(IMessage $message): ?string
    {
        $from = $message->getHeader(HeaderConsts::FROM);

        return $from?->getEmail() ?: null;
    }

    /**
     * @return array<string, string>
     */
    private function interestingHeaders(IMessage $message): array
    {
        $headers = [];

        foreach (['From', 'To', 'Cc', 'Reply-To', 'Date', 'Message-ID', 'In-Reply-To', 'References', 'Subject'] as $name) {
            $value = $message->getHeaderValue($name);

            if (filled($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Metadata only — attachment content stays inside the stored raw MIME.
     *
     * @return array<int, array{filename: string|null, content_type: string|null, size: int}>
     */
    private function attachmentMetadata(IMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAllAttachmentParts() as $part) {
            $attachments[] = [
                'filename' => $part->getFilename(),
                'content_type' => $part->getContentType(),
                'size' => strlen((string) $part->getContent()),
            ];
        }

        return $attachments;
    }
}
