<?php

use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function replyFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Reply Co', 'slug' => 'reply-co']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'replybox', 'slug' => 'replybox']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-reply',
        'default_from_email' => 'support@example.com',
        'last_quota' => ['max_24_hour_send' => 5000, 'period' => 'day'],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'reply-token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);

    return [$user, $project, $source];
}

function receiveReplyableEmail($test, Source $source, string $withAttachment = ''): void
{
    $attachment = $withAttachment !== '' ? implode("\r\n", [
        '--BOUND',
        'Content-Type: text/plain; charset=utf-8; name="notes.txt"',
        'Content-Disposition: attachment; filename="notes.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode($withAttachment),
    ]) : '';

    $mime = implode("\r\n", array_filter([
        'From: Maya Lin <maya@customer.test>',
        'To: hello@example.com',
        'Subject: Question about billing',
        'Message-ID: <q-1@customer.test>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="BOUND"',
        '',
        '--BOUND',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'How do I update my card?',
        $attachment,
        '--BOUND--',
        '',
    ], fn ($line) => $line !== null));

    $test->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'hello@example.com',
        'raw' => base64_encode($mime),
    ])->assertStatus(202);
}

it('replies within the thread using proper addressing and threading headers', function () {
    [$user, $project, $source] = replyFixture();

    Queue::fake();

    receiveReplyableEmail($this, $source);
    $thread = Thread::query()->firstOrFail();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/threads/{$thread->public_id}/reply", [
            'text' => "Sure!\n\nGo to Settings > Billing.",
        ])
        ->assertRedirect();

    $email = Email::query()->firstOrFail();

    expect($email->thread_id)->toBe($thread->id)
        ->and($email->from_email)->toBe('hello@example.com')
        ->and($email->recipients()->where('type', 'to')->value('email'))->toBe('maya@customer.test')
        ->and($email->subject)->toBe('Re: Question about billing')
        ->and($email->headers['In-Reply-To'])->toBe('<q-1@customer.test>')
        ->and($email->headers['References'])->toContain('q-1@customer.test')
        ->and($email->html)->toContain('<p>Sure!</p>')
        ->and($thread->fresh()->message_count)->toBe(2)
        ->and($thread->fresh()->last_direction)->toBe('outbound');
});

it('starts a new conversation from the compose modal', function () {
    [$user, $project] = replyFixture();

    Queue::fake();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/inbox/compose", [
            'to' => 'lead@customer.test',
            'subject' => 'Welcome aboard',
            'text' => 'Glad to have you!',
        ])
        ->assertRedirect();

    $email = Email::query()->firstOrFail();

    expect($email->from_email)->toBe('support@example.com')
        ->and($email->thread)->not->toBeNull()
        ->and($email->thread->subject)->toBe('Welcome aboard')
        ->and($email->thread->last_direction)->toBe('outbound');
});

it('blocks replies from members without send permission', function () {
    [$user, $project, $source] = replyFixture();

    Queue::fake();

    receiveReplyableEmail($this, $source);
    $thread = Thread::query()->firstOrFail();

    $reader = User::factory()->create();
    $project->workspace->users()->attach($reader, ['role' => 'read_only']);

    $this->actingAs($reader)
        ->post("/projects/{$project->slug}/threads/{$thread->public_id}/reply", ['text' => 'nope'])
        ->assertForbidden();
});

it('sends uploaded files as reply attachments', function () {
    [$user, $project, $source] = replyFixture();

    Queue::fake();

    receiveReplyableEmail($this, $source);
    $thread = Thread::query()->firstOrFail();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/threads/{$thread->public_id}/reply", [
            'text' => 'Attached the invoice.',
            'attachments' => [
                UploadedFile::fake()->createWithContent('invoice.txt', 'invoice contents'),
            ],
        ])
        ->assertRedirect();

    $email = Email::query()->firstOrFail();

    expect($email->attachments)->toHaveCount(1)
        ->and($email->attachments[0]->filename)->toBe('invoice.txt')
        ->and(Storage::disk($email->mime_disk)->get($email->mime_path))
        ->toContain('invoice.txt');
});

it('forwards the latest inbound message with its original attachments', function () {
    [$user, $project, $source] = replyFixture();

    Queue::fake();

    receiveReplyableEmail($this, $source, withAttachment: 'attachment body here');
    $thread = Thread::query()->firstOrFail();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/threads/{$thread->public_id}/forward", [
            'to' => 'teammate@example.org',
            'text' => 'Can you take this one?',
        ])
        ->assertRedirect();

    $email = Email::query()->firstOrFail();

    expect($email->from_email)->toBe('hello@example.com')
        ->and($email->recipients()->where('type', 'to')->value('email'))->toBe('teammate@example.org')
        ->and($email->subject)->toBe('Fwd: Question about billing')
        ->and($email->text)->toContain('Can you take this one?')
        ->and($email->text)->toContain('---------- Forwarded message ----------')
        ->and($email->text)->toContain('How do I update my card?')
        ->and($email->attachments)->toHaveCount(1)
        ->and($email->attachments[0]->filename)->toBe('notes.txt');
});

it('streams inbound attachments from the stored mime', function () {
    [$user, $project, $source] = replyFixture();

    Queue::fake();

    receiveReplyableEmail($this, $source, withAttachment: 'attachment body here');
    $inbound = InboundEmail::query()->firstOrFail();

    expect($inbound->attachments)->toHaveCount(1)
        ->and($inbound->attachments[0]['filename'])->toBe('notes.txt');

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbound/{$inbound->public_id}/attachments/0")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="notes.txt"')
        ->assertSee('attachment body here');

    $this->actingAs($user)
        ->get("/projects/{$project->slug}/inbound/{$inbound->public_id}/attachments/5")
        ->assertNotFound();
});
