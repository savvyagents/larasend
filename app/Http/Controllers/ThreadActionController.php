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
            'reply_all' => ['nullable', 'boolean'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
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
        $source = $this->projectContext->currentSource($project);

        try {
            $this->sendService->send($project, $source, [
                'from' => $lastInbound->to_email,
                'to' => [$lastInbound->from_email],
                'cc' => collect($this->recipientList($validated['cc'] ?? ''))
                    ->merge(($validated['reply_all'] ?? false) ? $this->replyAllRecipients($lastInbound) : [])
                    ->reject(fn (string $email): bool => collect([$lastInbound->from_email, $lastInbound->to_email, $source?->default_from_email])->filter()->contains(fn (string $own): bool => strcasecmp(trim($email), trim($own)) === 0))
                    ->unique(fn (string $email): string => Str::lower($email))
                    ->values()
                    ->all(),
                'bcc' => $this->recipientList($validated['bcc'] ?? ''),
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
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
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
                'to' => $this->recipientList($validated['to']),
                'cc' => $this->recipientList($validated['cc'] ?? ''),
                'bcc' => $this->recipientList($validated['bcc'] ?? ''),
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

    /** @return array<int, string> */
    private function recipientList(string $value): array
    {
        $recipients = collect(explode(',', $value))->map(fn (string $email): string => trim($email))->filter()->values();

        if ($recipients->contains(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages(['cc' => 'CC and BCC must contain valid comma-separated email addresses.']);
        }

        return $recipients->all();
    }

    /** @return array<int, string> */
    private function replyAllRecipients(InboundEmail $inbound): array
    {
        return $this->recipientList(collect([
            $inbound->headers['To'] ?? $inbound->headers['to'] ?? '',
            $inbound->headers['Cc'] ?? $inbound->headers['cc'] ?? '',
        ])->filter()->implode(','));
    }

    public function bulk(Request $request, string $projectSlug): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $project = $this->projectContext->projectFor($user, $projectSlug);
        $validated = $request->validate([
            'thread_ids' => ['required', 'array', 'min:1', 'max:50'],
            'thread_ids.*' => ['string'],
            'action' => ['required', 'in:archive,open,pending,close,assign,mark_read,mark_unread'],
            'assigned_to_user_id' => ['nullable', 'integer'],
        ]);

        if ($validated['action'] === 'assign' && ($validated['assigned_to_user_id'] ?? null) !== null) {
            abort_unless($project->workspace->users()->whereKey($validated['assigned_to_user_id'])->exists(), 422);
        }

        $threads = $project->threads()->whereIn('public_id', $validated['thread_ids'])->get();
        abort_unless($threads->count() === count(array_unique($validated['thread_ids'])), 404);

        foreach ($threads as $thread) {
            match ($validated['action']) {
                'archive' => $thread->forceFill(['archived_at' => now()])->save(),
                'open', 'pending', 'close' => $thread->forceFill(['status' => $validated['action'] === 'close' ? 'closed' : $validated['action']])->save(),
                'assign' => $thread->forceFill(['assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null])->save(),
                'mark_read', 'mark_unread' => $thread->userStates()->updateOrCreate(['user_id' => $user->id], ['read_at' => $validated['action'] === 'mark_read' ? now() : null]),
            };
            $this->recordEvent($thread, 'bulk_'.$validated['action']);
        }

        return back();
    }

    public function read(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);
        $thread->userStates()->updateOrCreate(['user_id' => Auth::id()], ['read_at' => now(), 'last_viewed_at' => now()]);

        return back();
    }

    public function unread(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->userStates()->updateOrCreate(['user_id' => Auth::id()], ['read_at' => null]);

        return back();
    }

    public function archive(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['archived_at' => now()])->save();
        $this->recordEvent($thread, 'archived');

        return back();
    }

    public function unarchive(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['archived_at' => null])->save();
        $this->recordEvent($thread, 'restored');

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
        $this->recordEvent($thread, 'snoozed', ['until' => $until->toIso8601String()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Snoozed until '.$until->format('D, M j g:ia').'.']);

        return back();
    }

    public function unsnooze(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $this->authorizeThread($thread);

        $thread->forceFill(['snoozed_until' => null])->save();
        $this->recordEvent($thread, 'unsnoozed');

        return back();
    }

    public function updateWorkflow(Request $request, string $projectSlug, Thread $thread): RedirectResponse
    {
        $project = $this->authorizeThread($thread);
        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,pending,closed'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigned_to_user_id' => ['nullable', 'integer'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:32'],
        ]);

        if (array_key_exists('assigned_to_user_id', $validated) && $validated['assigned_to_user_id'] !== null) {
            abort_unless($project->workspace->users()->whereKey($validated['assigned_to_user_id'])->exists(), 422);
        }

        $before = $thread->only(['status', 'priority', 'assigned_to_user_id', 'tags']);
        $thread->forceFill($validated)->save();
        $this->recordEvent($thread, 'workflow_updated', ['before' => $before, 'after' => $thread->only(array_keys($validated))]);

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

    /** @param array<string, mixed> $metadata */
    private function recordEvent(Thread $thread, string $type, array $metadata = []): void
    {
        $thread->events()->create(['user_id' => Auth::id(), 'type' => $type, 'metadata' => $metadata]);
    }
}
