<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use App\Services\EmailSendService;
use App\Support\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

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
                'html' => $this->textToHtml($validated['text']),
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
        ]);

        $source = $this->projectContext->currentSource($project);

        try {
            $email = $this->sendService->send($project, $source, [
                'from' => ($validated['from'] ?? null) ?: $source->default_from_email,
                'to' => [$validated['to']],
                'subject' => $validated['subject'],
                'text' => $validated['text'],
                'html' => $this->textToHtml($validated['text']),
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
