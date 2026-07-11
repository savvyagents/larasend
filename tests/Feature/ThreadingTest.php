<?php

use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailSendService;
use Illuminate\Support\Facades\Queue;

function threadingFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Threads Co', 'slug' => 'threads-co']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'threadbox', 'slug' => 'threadbox']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-threads',
        'default_from_email' => 'support@example.com',
        'last_quota' => ['max_24_hour_send' => 5000, 'period' => 'day'],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'thread-token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);

    return [$workspace, $project, $source];
}

function threadedInboundMime(string $subject, string $messageId, ?string $inReplyTo = null): string
{
    return implode("\r\n", array_filter([
        'From: Maya Lin <maya@customer.test>',
        'To: support@example.com',
        "Subject: {$subject}",
        "Message-ID: <{$messageId}>",
        $inReplyTo ? "In-Reply-To: <{$inReplyTo}>" : null,
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Thread body content.',
        '',
    ]));
}

it('threads replies through the in-reply-to chain', function () {
    [, , $source] = threadingFixture();

    Queue::fake();

    $post = fn (string $mime) => $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode($mime),
    ])->assertStatus(202);

    $post(threadedInboundMime('Invoice question', 'msg-1@customer.test'));
    $post(threadedInboundMime('Totally different topic', 'msg-2@customer.test'));
    $post(threadedInboundMime('Re: Invoice question', 'msg-3@customer.test', 'msg-1@customer.test'));

    expect(Thread::query()->count())->toBe(2);

    $invoiceThread = InboundEmail::query()->where('message_id', 'msg-1@customer.test')->firstOrFail()->thread;

    expect($invoiceThread->message_count)->toBe(2)
        ->and($invoiceThread->read_at)->toBeNull()
        ->and($invoiceThread->last_direction)->toBe('inbound')
        ->and(InboundEmail::query()->where('message_id', 'msg-3@customer.test')->value('thread_id'))->toBe($invoiceThread->id);
});

it('threads an outbound reply into the inbound conversation by subject and participant', function () {
    [, $project, $source] = threadingFixture();

    Queue::fake();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(threadedInboundMime('Need a refund', 'msg-9@customer.test')),
    ])->assertStatus(202);

    $email = app(EmailSendService::class)->send($project, $source, [
        'from' => 'Support <support@example.com>',
        'to' => ['maya@customer.test'],
        'subject' => 'Re: Need a refund',
        'text' => 'Refund issued!',
    ]);

    $thread = Thread::query()->firstOrFail();

    expect(Thread::query()->count())->toBe(1)
        ->and($email->thread_id)->toBe($thread->id)
        ->and($thread->message_count)->toBe(2)
        ->and($thread->last_direction)->toBe('outbound')
        ->and($thread->read_at)->not->toBeNull();
});

it('keeps unrelated subjects in separate threads and normalizes reply prefixes', function () {
    expect(Thread::subjectKey('RE: Fwd: Hello World'))->toBe('hello world')
        ->and(Thread::subjectKey('re[2]: Hello World'))->toBe('hello world')
        ->and(Thread::subjectKey(null))->toBe('(no subject)');
});

it('backfills threads for existing history in chronological order', function () {
    [, $project, $source] = threadingFixture();

    Queue::fake();

    // Simulate pre-threading history: rows created without thread_id.
    $original = InboundEmail::create([
        'public_id' => 'inbound_backfill1',
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'from_email' => 'maya@customer.test',
        'to_email' => 'support@example.com',
        'subject' => 'Old conversation',
        'text' => 'first',
        'message_id' => 'old-1@customer.test',
        'mime_path' => 'inbound/x1.eml',
        'received_at' => now()->subDays(2),
    ]);
    InboundEmail::create([
        'public_id' => 'inbound_backfill2',
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'from_email' => 'maya@customer.test',
        'to_email' => 'support@example.com',
        'subject' => 'Re: Old conversation',
        'text' => 'second',
        'message_id' => 'old-2@customer.test',
        'in_reply_to' => 'old-1@customer.test',
        'mime_path' => 'inbound/x2.eml',
        'received_at' => now()->subDay(),
    ]);

    $this->artisan('larasend:thread-backfill')
        ->expectsOutputToContain('Threaded 2 message(s).')
        ->assertExitCode(0);

    expect(Thread::query()->count())->toBe(1)
        ->and($original->fresh()->thread->message_count)->toBe(2);
});
