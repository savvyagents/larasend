<?php

namespace App\Http\Controllers;

use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use App\Services\EmailSendService;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

class ThreadActionController extends Controller
{
    public function __construct(
        private ProjectContext $projectContext,
        private EmailSendService $sendService,
    ) {}

    /**
     * Reply inside a conversation: goes out as the address the thread was
     * received on, to the last inbound sender, with proper threading headers
     * so every mail client (and our own resolver) keeps it in the thread.
     */
    public function reply(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $project = $this->authorizeThread($thread);

        abort_unless($project->workspace->canSendEmail(Auth::user()), 403);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:100000'],
            'html' => ['nullable', 'string', 'max:400000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $lastInbound = $thread->inboundEmails()->latest('received_at')->first();

        if (! $lastInbound) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This thread has no inbound message to reply to yet.']);

            return back();
        }

        $references = collect(preg_split('/\s+/', (string) ($lastInbound->headers['References'] ?? '')) ?: [])
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->push('<'.$lastInbound->message_id.'>')
            ->unique()
            ->implode(' ');

        $subject = Str::startsWith(Str::lower((string) $thread->subject), 're:')
            ? (string) $thread->subject
            : 'Re: '.$thread->subject;

        try {
            $this->sendService->send($project, $this->projectContext->currentSource($project), [
                'from' => $lastInbound->to_email,
                'to' => [$lastInbound->from_email],
                'subject' => $subject,
                'text' => $validated['text'],
                'html' => ($validated['html'] ?? null) ?: $this->textToHtml($validated['text']),
                'attachments' => $this->uploadedAttachments($request),
                'headers' => array_filter([
                    'In-Reply-To' => $lastInbound->message_id ? '<'.$lastInbound->message_id.'>' : null,
                    'References' => $references ?: null,
                ]),
            ]);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->implode(' ')]);

            return back();
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Could not send the reply: '.$exception->getMessage()]);

            return back();
        }

        return back();
    }

    /**
     * Start a brand-new conversation from the inbox composer.
     */
    public function compose(Request $request, string $projectSlug): RedirectResponse
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $project = $this->projectContext->projectFor($user, is_string($request->route('project')) ? $request->route('project') : null);

        abort_unless($project->workspace->canSendEmail($user), 403);

        $validated = $request->validate([
            'from' => ['nullable', 'email:rfc'],
            'to' => ['required', 'email:rfc'],
            'subject' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:100000'],
            'html' => ['nullable', 'string', 'max:400000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $source = $this->projectContext->currentSource($project);

        try {
            $email = $this->sendService->send($project, $source, [
                'from' => ($validated['from'] ?? null) ?: $source->default_from_email,
                'to' => [$validated['to']],
                'subject' => $validated['subject'],
                'text' => $validated['text'],
                'html' => ($validated['html'] ?? null) ?: $this->textToHtml($validated['text']),
                'attachments' => $this->uploadedAttachments($request),
            ]);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->implode(' ')]);

            return back();
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Could not send: '.$exception->getMessage()]);

            return back();
        }

        return redirect("/projects/{$project->slug}/inbox?thread=".$email->thread?->public_id);
    }

    /**
     * Forward the latest inbound message to someone new, keeping the
     * original attachments and quoting the original in the body.
     */
    public function forward(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $project = $this->authorizeThread($thread);

        abort_unless($project->workspace->canSendEmail(Auth::user()), 403);

        $validated = $request->validate([
            'to' => ['required', 'email:rfc'],
            'text' => ['nullable', 'string', 'max:100000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $lastInbound = $thread->inboundEmails()->latest('received_at')->first();

        if (! $lastInbound) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'This thread has no inbound message to forward yet.']);

            return back();
        }

        $subject = Str::startsWith(Str::lower((string) $thread->subject), ['fwd:', 'fw:'])
            ? (string) $thread->subject
            : 'Fwd: '.$thread->subject;

        $note = trim($validated['text'] ?? '');
        $quoted = implode("\n", [
            '---------- Forwarded message ----------',
            'From: '.trim(($lastInbound->from_name ? $lastInbound->from_name.' ' : '').'<'.$lastInbound->from_email.'>'),
            'Date: '.$lastInbound->received_at?->toRfc2822String(),
            'Subject: '.$lastInbound->subject,
            'To: '.$lastInbound->to_email,
            '',
            (string) $lastInbound->text,
        ]);
        $text = ($note !== '' ? $note."\n\n" : '').$quoted;

        try {
            $this->sendService->send($project, $this->projectContext->currentSource($project), [
                'from' => $lastInbound->to_email,
                'to' => [$validated['to']],
                'subject' => $subject,
                'text' => $text,
                'html' => $this->textToHtml($text),
                'attachments' => [
                    ...$this->inboundAttachments($lastInbound),
                    ...$this->uploadedAttachments($request),
                ],
            ]);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->implode(' ')]);

            return back();
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Could not forward: '.$exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Forwarded to {$validated['to']}."]);

        return back();
    }

    /**
     * @return array<int, array{filename: string, content_type: string, content: string}>
     */
    private function uploadedAttachments(Request $request): array
    {
        $files = $request->file('attachments', []);

        return collect(is_array($files) ? $files : [$files])
            ->map(fn (UploadedFile $file): array => [
                'filename' => $file->getClientOriginalName() ?: 'attachment',
                'content_type' => $file->getMimeType() ?: 'application/octet-stream',
                'content' => base64_encode((string) file_get_contents($file->getRealPath())),
            ])
            ->all();
    }

    /**
     * Attachment bytes live only in the stored raw MIME — re-parse it to
     * carry the original files along with a forward.
     *
     * @return array<int, array{filename: string, content_type: string, content: string}>
     */
    private function inboundAttachments(InboundEmail $inbound): array
    {
        if (! Storage::disk($inbound->mime_disk)->exists($inbound->mime_path)) {
            return [];
        }

        $message = app(MailMimeParser::class)->parse(
            Storage::disk($inbound->mime_disk)->get($inbound->mime_path),
            autoClose: true,
        );

        return collect($message->getAllAttachmentParts())
            ->map(fn ($part, int $index): array => [
                'filename' => $part->getFilename() ?: "attachment-{$index}",
                'content_type' => $part->getContentType() ?: 'application/octet-stream',
                'content' => base64_encode((string) $part->getContent()),
            ])
            ->values()
            ->all();
    }

    private function textToHtml(string $text): string
    {
        return collect(preg_split('/\n{2,}/', trim($text)) ?: [])
            ->map(fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph)), false).'</p>')
            ->implode("\n");
    }

    public function read(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function unread(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['read_at' => null])->save();

        return back();
    }

    public function archive(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['archived_at' => now()])->save();

        return back();
    }

    public function unarchive(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['archived_at' => null])->save();

        return back();
    }

    /**
     * Hide a conversation until a preset time. A new inbound message
     * wakes it early (see ThreadResolver::applyActivity).
     */
    public function snooze(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $validated = $request->validate([
            'until' => ['required', 'in:later_today,tomorrow,next_week'],
        ]);

        $until = match ($validated['until']) {
            'later_today' => now()->addHours(3),
            'tomorrow' => now()->addDay()->setTime(9, 0),
            'next_week' => now()->next('monday')->setTime(9, 0),
        };

        $thread->forceFill(['snoozed_until' => $until, 'read_at' => $thread->read_at ?? now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Snoozed until '.$until->format('D, M j g:ia').'.']);

        return back();
    }

    public function unsnooze(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['snoozed_until' => null])->save();

        return back();
    }

    /**
     * Internal note on the conversation — visible to the team in the
     * timeline, never emailed. Any member can annotate, including
     * read-only ones.
     */
    public function storeNote(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $thread->notes()->create([
            'user_id' => Auth::id(),
            'body' => $validated['body'],
        ]);

        return back();
    }

    private function authorizeThread(Thread $thread): Project
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $projectSlug = request()->route('project');
        $project = $this->projectContext->projectFor($user, is_string($projectSlug) ? $projectSlug : null);

        abort_unless($thread->project_id === $project->id, 404);

        return $project;
    }
}
