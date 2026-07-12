<?php

namespace App\Http\Controllers;

use App\Models\InboundEmail;
use App\Models\User;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Attachment content is never duplicated into the database — it lives in
 * the stored raw MIME. This re-parses the .eml on demand and streams the
 * requested part.
 */
class InboundAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        string $projectSlug,
        InboundEmail $inboundEmail,
        int $index,
        ProjectContext $context,
        MailMimeParser $parser,
    ): Response {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $project = $context->projectFor($user, is_string($request->route('project')) ? $request->route('project') : null);

        abort_unless($inboundEmail->project_id === $project->id, 404);
        abort_unless(Storage::disk($inboundEmail->mime_disk)->exists($inboundEmail->mime_path), 404);

        $message = $parser->parse(Storage::disk($inboundEmail->mime_disk)->get($inboundEmail->mime_path), autoClose: true);
        $parts = $message->getAllAttachmentParts();

        abort_unless(isset($parts[$index]), 404);

        $part = $parts[$index];
        $filename = $part->getFilename() ?: "attachment-{$index}";

        return response((string) $part->getContent(), 200, [
            'Content-Type' => $part->getContentType() ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
