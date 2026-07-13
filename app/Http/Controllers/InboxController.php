<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Thread;
use App\Models\ThreadNote;
use App\Models\User;
use App\Support\ProjectContext;
use Illuminate\Database\Eloquent\Builder;
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
        $assigned = $request->string('assigned')->toString();
        $page = max(1, $request->integer('page', 1));
        $threads = $this->threadsFor($project, $user->id, $mailbox, $address, $search, $assigned, $page);
        $selected = $this->selectedThread($project, $request->string('thread')->toString(), $threads->first()?->public_id);

        // Opening a conversation reads it — standard mail client behavior,
        // no separate round trip. Explicit unread stays available.
        if ($selected && $request->string('thread')->toString() !== '') {
            $selected->userStates()->updateOrCreate(
                ['user_id' => $user->id],
                ['read_at' => now(), 'last_viewed_at' => now()],
            );
        }

        return Inertia::render('Inbox', [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'path' => '/projects/'.$project->slug,
                'dashboard_path' => $context->sectionPath($project),
            ],
            'projects' => $context->projectsFor($user)->map(function (Project $workspaceProject) use ($context, $project): array {
                $source = $workspaceProject->sources->first();

                return [
                    'name' => $workspaceProject->name,
                    'slug' => $workspaceProject->slug,
                    'environment' => $source?->environment ?? $workspaceProject->default_environment,
                    'provider_label' => $source?->provider->label() ?? 'Not connected',
                    'is_current' => $workspaceProject->is($project),
                    'href' => $context->sectionPath($workspaceProject, 'inbox'),
                ];
            })->values(),
            'canSend' => $project->workspace->canSendEmail($user),
            'teamMembers' => $project->workspace->users()->select('users.id', 'users.name', 'users.email')->orderBy('name')->get(),
            'templates' => $project->templates()->select('id', 'name', 'subject', 'html', 'text')->orderBy('name')->get(),
            'mailbox' => $mailbox,
            'address' => $address,
            'filters' => ['q' => $search, 'assigned' => $assigned],
            'addresses' => $this->addresses($project, $user->id, $mailbox),
            'counts' => [
                'inbox' => $project->threads()->whereNull('archived_at')->where('status', '!=', 'closed')->where($this->notSnoozed(...))->count(),
                'unread' => $project->threads()->whereNull('archived_at')->where('status', '!=', 'closed')->where($this->notSnoozed(...))->whereDoesntHave('userStates', fn ($query) => $query->where('user_id', $user->id)->whereNotNull('read_at'))->count(),
                'snoozed' => $project->threads()->where('snoozed_until', '>', now())->count(),
                'archived' => $project->threads()->whereNotNull('archived_at')->count(),
                'closed' => $project->threads()->where('status', 'closed')->count(),
            ],
            'threads' => $threads->take(50)->map(fn (Thread $thread): array => $this->serializeThread($thread, $user->id)),
            'pagination' => ['page' => $page, 'has_more' => $threads->count() > 50],
            'selectedThread' => $selected ? [
                ...$this->serializeThread($selected, $user->id),
                'messages' => $this->messagesFor($selected),
                'reply_from' => $this->replyFromFor($selected, $source?->default_from_email),
                'active_viewers' => $selected->userStates()->with('user:id,name')->where('user_id', '!=', $user->id)->where('last_viewed_at', '>=', now()->subMinutes(2))->get()->map(fn ($state): array => ['id' => $state->user_id, 'name' => $state->user?->name ?? 'Teammate'])->values(),
                'activity' => $selected->events()->with('user:id,name')->latest()->limit(20)->get()->map(fn ($event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'actor' => $event->user?->name ?? 'System',
                    'metadata' => $event->metadata ?? [],
                    'at_human' => $event->created_at?->diffForHumans(short: true),
                ]),
            ] : null,
        ]);
    }

    /**
     * @return Collection<int, Thread>
     */
    private function threadsFor(Project $project, int $userId, string $mailbox, ?string $address, string $search, string $assigned, int $page)
    {
        return $project->threads()
            ->tap(fn (Builder $query) => $this->applyMailbox($query, $mailbox, $userId))
            ->when($address, fn ($query) => $query->whereHas(
                'inboundEmails',
                fn ($messages) => $messages->whereRaw('LOWER(to_email) = ?', [Str::lower($address)]),
            ))
            ->when($assigned === 'mine', fn ($query) => $query->where('assigned_to_user_id', $userId))
            ->when($assigned === 'unassigned', fn ($query) => $query->whereNull('assigned_to_user_id'))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->whereLike('subject', "%{$search}%")
                        ->orWhereLike('last_snippet', "%{$search}%")
                        ->orWhereLike('participants', "%{$search}%")
                        ->orWhereHas('inboundEmails', fn ($messages) => $messages->whereLike('text', "%{$search}%"))
                        ->orWhereHas('emails', fn ($messages) => $messages->whereLike('text', "%{$search}%"));
                });
            })
            ->orderByDesc('last_activity_at')
            ->with(['assignedTo:id,name', 'userStates' => fn ($query) => $query->where('user_id', $userId)])
            ->offset(($page - 1) * 50)
            ->limit(51)
            ->get();
    }

    private function applyMailbox(Builder $query, string $mailbox, int $userId): void
    {
        $query
            ->when($mailbox === 'inbox', fn ($threads) => $threads->whereNull('archived_at')->where('status', '!=', 'closed')->where($this->notSnoozed(...)))
            ->when($mailbox === 'unread', fn ($threads) => $threads->whereNull('archived_at')->where('status', '!=', 'closed')->where($this->notSnoozed(...))->whereDoesntHave('userStates', fn ($state) => $state->where('user_id', $userId)->whereNotNull('read_at')))
            ->when($mailbox === 'snoozed', fn ($threads) => $threads->where('snoozed_until', '>', now()))
            ->when($mailbox === 'archived', fn ($threads) => $threads->whereNotNull('archived_at'))
            ->when($mailbox === 'closed', fn ($threads) => $threads->where('status', 'closed'));
    }

    private function notSnoozed(mixed $query): void
    {
        $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
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
    private function serializeThread(Thread $thread, int $userId): array
    {
        $state = $thread->userStates->firstWhere('user_id', $userId);

        return [
            'public_id' => $thread->public_id,
            'subject' => $thread->subject,
            'participants' => $thread->participants ?? [],
            'snippet' => $thread->last_snippet,
            'direction' => $thread->last_direction,
            'message_count' => $thread->message_count,
            'unread' => $state?->read_at === null,
            'archived' => $thread->archived_at !== null,
            'snoozed' => $thread->snoozed_until?->isFuture() === true,
            'snoozed_until_human' => $thread->snoozed_until?->isFuture() === true
                ? $thread->snoozed_until->diffForHumans(short: true)
                : null,
            'last_activity_at' => $thread->last_activity_at?->toIso8601String(),
            'last_activity_human' => $thread->last_activity_at?->diffForHumans(short: true),
            'status' => $thread->status,
            'priority' => $thread->priority,
            'tags' => $thread->tags ?? [],
            'assigned_to' => $thread->assignedTo ? ['id' => $thread->assignedTo->id, 'name' => $thread->assignedTo->name] : null,
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

        $outbound = $thread->emails()->with(['recipients', 'attachments', 'events' => fn ($query) => $query->latest('occurred_at')->limit(1)])->get()->map(fn (Email $message): array => [
            'id' => $message->public_id,
            'direction' => 'outbound',
            'from' => trim(($message->from_name ? $message->from_name.' ' : '').'<'.$message->from_email.'>'),
            'from_email' => $message->from_email,
            'to' => $message->recipients->where('type', 'to')->pluck('email')->implode(', '),
            'subject' => $message->subject,
            'text' => $message->text,
            'html' => $message->html,
            'attachments' => $message->attachments->map(fn ($attachment): array => ['filename' => $attachment->filename, 'content_type' => $attachment->content_type, 'size' => $attachment->size])->all(),
            'status' => $message->status,
            'status_detail' => $message->events->first()?->payload['diagnosticCode'] ?? $message->events->first()?->payload['diagnostic'] ?? null,
            'can_retry' => in_array($message->status, ['failed', 'bounced'], true),
            'at' => $message->created_at?->toIso8601String(),
            'at_human' => $message->created_at?->diffForHumans(short: true),
        ]);

        $notes = $thread->notes()->with('user')->get()->map(fn (ThreadNote $note): array => [
            'id' => 'note-'.$note->id,
            'direction' => 'note',
            'from' => $note->user?->name ?? 'Someone',
            'from_email' => '',
            'to' => '',
            'subject' => null,
            'text' => $note->body,
            'html' => null,
            'attachments' => [],
            'status' => null,
            'at' => $note->created_at?->toIso8601String(),
            'at_human' => $note->created_at?->diffForHumans(short: true),
        ]);

        return $inbound->concat($outbound)
            ->concat($notes)
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
    private function addresses(Project $project, int $userId, string $mailbox): array
    {
        return $project->inboundEmails()
            ->whereNotNull('thread_id')
            ->whereHas('thread', fn (Builder $query) => $this->applyMailbox($query, $mailbox, $userId))
            ->selectRaw('LOWER(to_email) as address, count(distinct thread_id) as total')
            ->groupByRaw('LOWER(to_email)')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row): array => ['address' => $row->address, 'count' => (int) $row->total])
            ->all();
    }
}
