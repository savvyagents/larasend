<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Thread;
use App\Models\User;
use App\Support\ProjectContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __invoke(Request $request, ProjectContext $context): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $projectSlug = $request->route('project');
        $project = $context->projectFor($user, is_string($projectSlug) ? $projectSlug : null);
        $source = $context->currentSource($project);

        $mailbox = (string) $request->string('mailbox', 'inbox');
        $address = $request->string('address')->toString() ?: null;
        $search = $request->string('q')->toString();
        $threads = $this->threadsFor($project, $mailbox, $address, $search);
        $selected = $this->selectedThread($project, $request->string('thread')->toString(), $threads->first()?->public_id);

        // Opening a conversation reads it — standard mail client behavior,
        // no separate round trip. Explicit unread stays available.
        if ($selected && $selected->read_at === null && $request->string('thread')->toString() !== '') {
            $selected->forceFill(['read_at' => now()])->save();
        }

        return Inertia::render('Inbox', [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'path' => '/projects/'.$project->slug,
                'dashboard_path' => $context->sectionPath($project),
            ],
            'canSend' => $project->workspace->canSendEmail($user),
            'mailbox' => $mailbox,
            'address' => $address,
            'filters' => ['q' => $search],
            'addresses' => $this->addresses($project),
            'counts' => [
                'inbox' => $project->threads()->whereNull('archived_at')->count(),
                'unread' => $project->threads()->whereNull('archived_at')->whereNull('read_at')->count(),
                'archived' => $project->threads()->whereNotNull('archived_at')->count(),
            ],
            'threads' => $threads->map(fn (Thread $thread): array => $this->serializeThread($thread)),
            'selectedThread' => $selected ? [
                ...$this->serializeThread($selected),
                'messages' => $this->messagesFor($selected),
                'reply_from' => $this->replyFromFor($selected, $source?->default_from_email),
            ] : null,
        ]);
    }

    /**
     * @return Collection<int, Thread>
     */
    private function threadsFor(Project $project, string $mailbox, ?string $address, string $search)
    {
        return $project->threads()
            ->when($mailbox === 'inbox', fn ($query) => $query->whereNull('archived_at'))
            ->when($mailbox === 'unread', fn ($query) => $query->whereNull('archived_at')->whereNull('read_at'))
            ->when($mailbox === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($address, fn ($query) => $query->whereJsonContains('participants', Str::lower($address)))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->whereLike('subject', "%{$search}%")
                        ->orWhereLike('last_snippet', "%{$search}%")
                        ->orWhereLike('participants', "%{$search}%");
                });
            })
            ->orderByDesc('last_activity_at')
            ->limit(50)
            ->get();
    }

    private function selectedThread(Project $project, string $requested, ?string $fallback): ?Thread
    {
        $publicId = $requested !== '' ? $requested : $fallback;

        if ($publicId === null) {
            return null;
        }

        return $project->threads()->where('public_id', $publicId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeThread(Thread $thread): array
    {
        return [
            'public_id' => $thread->public_id,
            'subject' => $thread->subject,
            'participants' => $thread->participants ?? [],
            'snippet' => $thread->last_snippet,
            'direction' => $thread->last_direction,
            'message_count' => $thread->message_count,
            'unread' => $thread->read_at === null,
            'archived' => $thread->archived_at !== null,
            'last_activity_at' => $thread->last_activity_at?->toIso8601String(),
            'last_activity_human' => $thread->last_activity_at?->diffForHumans(short: true),
        ];
    }

    /**
     * Inbound and outbound messages interleaved chronologically — one
     * conversation, both directions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function messagesFor(Thread $thread): array
    {
        $inbound = $thread->inboundEmails()->get()->map(fn (InboundEmail $message): array => [
            'id' => $message->public_id,
            'direction' => 'inbound',
            'from' => trim(($message->from_name ? $message->from_name.' ' : '').'<'.$message->from_email.'>'),
            'from_email' => $message->from_email,
            'to' => $message->to_email,
            'subject' => $message->subject,
            'text' => $message->text,
            'html' => $message->html,
            'attachments' => $message->attachments ?? [],
            'status' => null,
            'at' => $message->received_at?->toIso8601String(),
            'at_human' => $message->received_at?->diffForHumans(short: true),
        ]);

        $outbound = $thread->emails()->with('recipients')->get()->map(fn (Email $message): array => [
            'id' => $message->public_id,
            'direction' => 'outbound',
            'from' => trim(($message->from_name ? $message->from_name.' ' : '').'<'.$message->from_email.'>'),
            'from_email' => $message->from_email,
            'to' => $message->recipients->where('type', 'to')->pluck('email')->implode(', '),
            'subject' => $message->subject,
            'text' => $message->text,
            'html' => $message->html,
            'attachments' => [],
            'status' => $message->status,
            'at' => $message->created_at?->toIso8601String(),
            'at_human' => $message->created_at?->diffForHumans(short: true),
        ]);

        return $inbound->concat($outbound)
            ->sortBy('at')
            ->values()
            ->all();
    }

    /**
     * Replies go out as the address the conversation was received on,
     * falling back to the source's default sender.
     */
    private function replyFromFor(Thread $thread, ?string $default): ?string
    {
        return $thread->inboundEmails()->latest('received_at')->value('to_email') ?? $default;
    }

    /**
     * Addresses that have received mail, for the mailbox rail filters.
     *
     * @return array<int, array{address: string, count: int}>
     */
    private function addresses(Project $project): array
    {
        return $project->inboundEmails()
            ->selectRaw('to_email, count(*) as total')
            ->groupBy('to_email')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row): array => ['address' => $row->to_email, 'count' => (int) $row->total])
            ->all();
    }
}
