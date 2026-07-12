<?php

use App\Models\Project;
use App\Models\Source;
use App\Models\Thread;
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

function receiveThreadedEmail($test, Source $source, string $subject, string $messageId, string $from = 'maya@customer.test'): void
{
    $mime = implode("\r\n", [
        "From: {$from}",
        'To: support@example.com',
        "Subject: {$subject}",
        "Message-ID: <{$messageId}>",
        'Content-Type: text/plain; charset=utf-8',
        '',
        "Body of {$subject}",
        '',
    ]);

    $test->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => $from,
        'to' => 'support@example.com',
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

it('marks a thread read when opened explicitly', function () {
    [$user, $project, $source] = inboxFixture();

    Queue::fake();

    receiveThreadedEmail($this, $source, 'Read me', 'inbox-3@customer.test');
    $thread = Thread::query()->firstOrFail();

    expect($thread->read_at)->toBeNull();

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbox?thread={$thread->public_id}")
        ->assertInertia(fn ($page) => $page->where('selectedThread.unread', false));

    expect($thread->fresh()->read_at)->not->toBeNull();
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
    expect($thread->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user)->post("/projects/{$project->slug}/threads/{$thread->public_id}/unread");
    expect($thread->fresh()->read_at)->toBeNull();
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
