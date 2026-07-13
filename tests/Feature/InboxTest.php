<?php

use App\Models\Project;
use App\Models\Source;
use App\Models\Thread;
use App\Models\ThreadUserState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function inboxFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Inbox Co', 'slug' => 'inbox-co']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'helpdesk', 'slug' => 'helpdesk']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-inbox',
        'default_from_email' => 'support@example.com',
        'webhook_token' => 'inbox-token-'.str()->random(8),
    ]);

    return [$user, $project, $source];
}

function receiveThreadedEmail($test, Source $source, string $subject, string $messageId, string $from = 'maya@customer.test', string $to = 'support@example.com'): void
{
    $mime = implode("\r\n", [
        "From: {$from}",
        "To: {$to}",
        "Subject: {$subject}",
        "Message-ID: <{$messageId}>",
        'Content-Type: text/plain; charset=utf-8',
        '',
        "Body of {$subject}",
        '',
    ]);

    $test->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => $from,
        'to' => $to,
        'raw' => base64_encode($mime),
    ])->assertStatus(202);
}

it('renders the inbox with threads, counts, and interleaved messages', function () {
    [$user, $project, $source] = inboxFixture();

    Queue::fake();

    receiveThreadedEmail($this, $source, 'First conversation', 'inbox-1@customer.test');
    receiveThreadedEmail($this, $source, 'Second conversation', 'inbox-2@customer.test');

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox")
        ->assertInertia(fn ($page) => $page
            ->component('Inbox')
            ->where('counts.inbox', 2)
            ->where('counts.unread', 2)
            ->has('threads', 2)
            ->where('threads.0.unread', true)
            ->where('threads.0.subject', 'Second conversation')
            ->has('selectedThread.messages', 1)
            ->where('selectedThread.reply_from', 'support@example.com')
            ->has('projects', 1)
            ->where('projects.0.name', 'helpdesk')
            ->where('projects.0.is_current', true)
            ->where('projects.0.href', '/projects/helpdesk/inbox')
            ->where('addresses.0.address', 'support@example.com'));
});

it('counts and filters addresses by distinct conversations in the current mailbox', function () {
    [$user, $project, $source] = inboxFixture();

    Queue::fake();

    receiveThreadedEmail($this, $source, 'Repeated conversation', 'address-1@customer.test');
    receiveThreadedEmail($this, $source, 'Repeated conversation', 'address-2@customer.test');
    receiveThreadedEmail($this, $source, 'Another conversation', 'address-3@customer.test');
    receiveThreadedEmail($this, $source, 'Archived address', 'address-4@customer.test', to: 'archive@example.com');

    $archivedThread = Thread::query()
        ->whereHas('inboundEmails', fn ($query) => $query->where('to_email', 'archive@example.com'))
        ->firstOrFail();
    $archivedThread->forceFill(['archived_at' => now()])->save();

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox?mailbox=inbox&address=support%40example.com")
        ->assertInertia(fn ($page) => $page
            ->has('addresses', 1)
            ->where('addresses.0.address', 'support@example.com')
            ->where('addresses.0.count', 2)
            ->has('threads', 2));

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox?mailbox=archived")
        ->assertInertia(fn ($page) => $page
            ->has('addresses', 1)
            ->where('addresses.0.address', 'archive@example.com')
            ->where('addresses.0.count', 1)
            ->has('threads', 1));
});

it('marks a thread read when opened explicitly', function () {
    [$user, $project, $source] = inboxFixture();

    Queue::fake();

    receiveThreadedEmail($this, $source, 'Read me', 'inbox-3@customer.test');
    $thread = Thread::query()->firstOrFail();

    expect($thread->read_at)->toBeNull();

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox?thread={$thread->public_id}")
        ->assertInertia(fn ($page) => $page->where('selectedThread.unread', false));

    expect(ThreadUserState::query()->whereBelongsTo($thread)->whereBelongsTo($user)->value('read_at'))->not->toBeNull();
});

it('archives, restores, and toggles unread through thread actions', function () {
    [$user, $project, $source] = inboxFixture();

    Queue::fake();

    receiveThreadedEmail($this, $source, 'Actionable', 'inbox-4@customer.test');
    $thread = Thread::query()->firstOrFail();

    $this->actingAs($user)->post("/projects/{$project->slug}/threads/{$thread->public_id}/archive");
    expect($thread->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($user)->post("/projects/{$project->slug}/threads/{$thread->public_id}/unarchive");
    expect($thread->fresh()->archived_at)->toBeNull();

    $this->actingAs($user)->post("/projects/{$project->slug}/threads/{$thread->public_id}/read");
    expect(ThreadUserState::query()->whereBelongsTo($thread)->whereBelongsTo($user)->value('read_at'))->not->toBeNull();

    $this->actingAs($user)->post("/projects/{$project->slug}/threads/{$thread->public_id}/unread");
    expect(ThreadUserState::query()->whereBelongsTo($thread)->whereBelongsTo($user)->value('read_at'))->toBeNull();
});

it('keeps read state personal while sharing assignment and workflow state', function () {
    [$owner, $project, $source] = inboxFixture();
    $member = User::factory()->create();
    $project->workspace->users()->attach($member, ['role' => 'member']);
    Queue::fake();
    receiveThreadedEmail($this, $source, 'Team work', 'team-work@customer.test');
    $thread = Thread::query()->firstOrFail();

    $this->actingAs($owner)->get("/projects/{$project->slug}/inbox?thread={$thread->public_id}")->assertSuccessful();

    $this->actingAs($member)
        ->get("/projects/{$project->slug}/inbox?mailbox=unread")
        ->assertInertia(fn ($page) => $page->has('threads', 1));

    $this->actingAs($owner)
        ->patch("/projects/{$project->slug}/threads/{$thread->public_id}/workflow", [
            'status' => 'pending',
            'priority' => 'high',
            'assigned_to_user_id' => $member->id,
        ])
        ->assertRedirect();

    expect($thread->fresh())
        ->status->toBe('pending')
        ->priority->toBe('high')
        ->assigned_to_user_id->toBe($member->id)
        ->and($thread->events()->where('type', 'workflow_updated')->exists())->toBeTrue();
});

it('bulk triages only project-scoped conversations and records activity', function () {
    [$user, $project, $source] = inboxFixture();
    Queue::fake();
    receiveThreadedEmail($this, $source, 'Bulk one', 'bulk-1@customer.test');
    receiveThreadedEmail($this, $source, 'Bulk two', 'bulk-2@customer.test');
    $threads = Thread::query()->orderBy('id')->get();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/inbox/bulk", [
            'thread_ids' => $threads->pluck('public_id')->all(),
            'action' => 'close',
        ])
        ->assertRedirect();

    expect(Thread::query()->where('status', 'closed')->count())->toBe(2)
        ->and($threads->first()->events()->where('type', 'bulk_close')->exists())->toBeTrue();

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox?mailbox=closed")
        ->assertInertia(fn ($page) => $page->has('threads', 2));
});

it('filters mailboxes and blocks cross-project thread access', function () {
    [$user, $project, $source] = inboxFixture();

    Queue::fake();

    receiveThreadedEmail($this, $source, 'Stays inbox', 'inbox-5@customer.test');
    receiveThreadedEmail($this, $source, 'Gets archived', 'inbox-6@customer.test');

    $archived = Thread::query()->where('subject', 'Gets archived')->firstOrFail();
    $archived->forceFill(['archived_at' => now()])->save();

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox?mailbox=archived")
        ->assertInertia(fn ($page) => $page
            ->has('threads', 1)
            ->where('threads.0.subject', 'Gets archived'));

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/projects/{$project->slug}/threads/{$archived->public_id}/archive")
        ->assertNotFound();
});
