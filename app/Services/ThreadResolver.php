<?php

namespace App\Services;

use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Thread;
use Illuminate\Support\Str;

/**
 * Groups outbound and inbound messages into conversations. Resolution
 * order mirrors how real mail clients thread: the References/In-Reply-To
 * chain is authoritative; a normalized subject plus a shared participant
 * is the fallback for senders that strip threading headers; otherwise a
 * new thread starts.
 */
class ThreadResolver
{
    /**
     * Days after which a dormant thread no longer attracts subject-matched
     * messages — prevents years of "Invoice" emails collapsing into one.
     */
    private const SUBJECT_MATCH_WINDOW_DAYS = 60;

    public function attachInbound(InboundEmail $inbound): Thread
    {
        $participants = array_filter([
            Str::lower($inbound->from_email),
            Str::lower($inbound->to_email),
        ]);

        $thread = $this->findByReferences($inbound->project_id, $this->inboundReferences($inbound))
            ?? $this->findBySubject($inbound->project_id, $inbound->subject, $participants)
            ?? $this->createThread($inbound->workspace_id, $inbound->project_id, $inbound->subject);

        $inbound->forceFill(['thread_id' => $thread->id])->save();

        $this->applyActivity(
            $thread,
            direction: 'inbound',
            participants: $participants,
            snippet: $inbound->text,
            activityAt: $inbound->received_at,
        );

        return $thread;
    }

    public function attachOutbound(Email $email): Thread
    {
        $email->loadMissing('recipients');

        $participants = collect([Str::lower($email->from_email)])
            ->merge($email->recipients->pluck('email')->map(fn (string $address) => Str::lower($address)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $thread = $this->findByReferences($email->project_id, $this->outboundReferences($email))
            ?? $this->findBySubject($email->project_id, $email->subject, $participants)
            ?? $this->createThread($email->workspace_id, $email->project_id, $email->subject);

        $email->forceFill(['thread_id' => $thread->id])->save();

        $this->applyActivity(
            $thread,
            direction: 'outbound',
            participants: $participants,
            snippet: $email->text,
            activityAt: $email->created_at,
        );

        return $thread;
    }

    /**
     * @return array<int, string>
     */
    private function inboundReferences(InboundEmail $inbound): array
    {
        $references = (string) ($inbound->headers['References'] ?? '');

        return collect(preg_split('/\s+/', $references) ?: [])
            ->push((string) $inbound->in_reply_to)
            ->map(fn (string $id): string => trim($id, " \t<>"))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function outboundReferences(Email $email): array
    {
        $headers = $email->headers ?? [];
        $references = (string) ($headers['References'] ?? '');
        $inReplyTo = (string) ($headers['In-Reply-To'] ?? '');

        return collect(preg_split('/\s+/', $references) ?: [])
            ->push($inReplyTo)
            ->map(fn (string $id): string => trim($id, " \t<>"))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $references
     */
    private function findByReferences(int $projectId, array $references): ?Thread
    {
        if ($references === []) {
            return null;
        }

        $threadId = InboundEmail::query()
            ->where('project_id', $projectId)
            ->whereIn('message_id', $references)
            ->whereNotNull('thread_id')
            ->value('thread_id');

        $threadId ??= Email::query()
            ->where('project_id', $projectId)
            ->whereIn('ses_message_id', $references)
            ->whereNotNull('thread_id')
            ->value('thread_id');

        return $threadId ? Thread::query()->find($threadId) : null;
    }

    /**
     * @param  array<int, string>  $participants
     */
    private function findBySubject(int $projectId, ?string $subject, array $participants): ?Thread
    {
        if ($participants === []) {
            return null;
        }

        return Thread::query()
            ->where('project_id', $projectId)
            ->where('subject_key', Thread::subjectKey($subject))
            ->where('last_activity_at', '>=', now()->subDays(self::SUBJECT_MATCH_WINDOW_DAYS))
            ->where(function ($query) use ($participants): void {
                foreach ($participants as $participant) {
                    $query->orWhereJsonContains('participants', $participant);
                }
            })
            ->orderByDesc('last_activity_at')
            ->first();
    }

    private function createThread(int $workspaceId, int $projectId, ?string $subject): Thread
    {
        return Thread::query()->create([
            'public_id' => 'thread_'.Str::random(24),
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'subject' => $subject ?: '(no subject)',
            'subject_key' => Thread::subjectKey($subject),
            'participants' => [],
            'message_count' => 0,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $participants
     */
    private function applyActivity(Thread $thread, string $direction, array $participants, ?string $snippet, mixed $activityAt): void
    {
        $thread->forceFill([
            'participants' => collect($thread->participants ?? [])
                ->merge($participants)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'last_direction' => $direction,
            'last_snippet' => Str::limit(trim((string) $snippet), 140, '…') ?: $thread->last_snippet,
            'message_count' => $thread->message_count + 1,
            'last_activity_at' => $activityAt ?? now(),
            // A customer reply reopens the thread as unread and wakes it
            // from archive or snooze; your own outbound message never does.
            'read_at' => $direction === 'inbound' ? null : ($thread->read_at ?? now()),
            'archived_at' => $direction === 'inbound' ? null : $thread->archived_at,
            'snoozed_until' => $direction === 'inbound' ? null : $thread->snoozed_until,
            'status' => $direction === 'inbound' ? 'open' : $thread->status,
        ])->save();

        if ($direction === 'inbound') {
            $thread->userStates()->update(['read_at' => null]);
        }
    }
}
