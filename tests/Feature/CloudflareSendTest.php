<?php

use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Providers\CloudflareEmailProvider;
use App\Services\Providers\CloudflareSmtpTransportFactory;
use App\Services\Providers\EmailProviderFactory;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class CloudflareSpyTransport implements TransportInterface
{
    /** @var array<int, array{message: string, envelope: Envelope|null}> */
    public array $sent = [];

    public ?TransportException $failWith = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->sent[] = ['message' => $message->toString(), 'envelope' => $envelope];

        return new SentMessage($message, $envelope);
    }

    public function __toString(): string
    {
        return 'cloudflare-spy://';
    }
}

/**
 * @return array{0: Workspace, 1: Project, 2: Source, 3: string, 4: CloudflareSpyTransport}
 */
function cloudflareProjectFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme CF', 'slug' => 'acme-cf']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'driftwood', 'slug' => 'driftwood']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-1234567890',
        'default_from_email' => 'receipts@example.com',
        'last_quota' => [
            'max_24_hour_send' => 5000,
            'max_send_rate' => null,
            'sent_last_24_hours' => null,
            'period' => 'day',
        ],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);
    $issued = ApiKey::issue($project, 'Test key', $source);

    $spy = new CloudflareSpyTransport;
    app()->instance(CloudflareSmtpTransportFactory::class, new class($spy) extends CloudflareSmtpTransportFactory
    {
        public function __construct(private CloudflareSpyTransport $spy) {}

        public function create(Source $source): TransportInterface
        {
            return $this->spy;
        }
    });

    return [$workspace, $project, $source, $issued['plain_text'], $spy];
}

it('sends queued email through cloudflare smtp with all recipients in the envelope', function () {
    [$workspace, $project, $source, $token, $spy] = cloudflareProjectFixture();

    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'cc' => ['copy@example.com'],
        'bcc' => ['hidden@example.com'],
        'subject' => 'Cloudflare welcome',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

    expect($spy->sent)->toHaveCount(1);

    $transmittedMime = $spy->sent[0]['message'];
    $envelope = $spy->sent[0]['envelope'];
    $envelopeRecipients = collect($envelope->getRecipients())
        ->map(fn ($address) => $address->getAddress())
        ->all();

    expect($transmittedMime)->toContain('Cloudflare welcome')
        ->not->toContain('hidden@example.com')
        ->and($transmittedMime)->not->toMatch('/^Bcc:/mi')
        ->and($envelopeRecipients)->toContain('maya@example.com', 'copy@example.com', 'hidden@example.com')
        ->and($envelope->getSender()->getAddress())->toBe('receipts@example.com');

    preg_match('/^Message-ID:\s*<([^>]+)>/mi', $transmittedMime, $matches);

    expect($email->fresh())
        ->status->toBe('sent')
        ->ses_message_id->toBe($matches[1])
        ->and($email->events()->where('event_type', 'send')->exists())->toBeTrue()
        ->and($email->events()->where('event_type', 'send')->first()->payload['provider'])->toBe('cloudflare');
});

it('maps cloudflare smtp sender rejections to an actionable setup error', function () {
    [$workspace, $project, $source, $token, $spy] = cloudflareProjectFixture();

    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Rejected send',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();
    $spy->failWith = new TransportException(
        'Expected response code "354" but got code "550", with message "550 5.7.1 Sender denied".',
    );

    $job = new SendQueuedEmail($email->id);
    $caught = null;

    try {
        $job->handle(app(EmailProviderFactory::class));
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('Add "example.com" as a sending domain')
        ->and($email->fresh()->status)->toBe('sending');

    $job->failed($caught);

    expect($email->fresh()->status)->toBe('failed')
        ->and($email->events()->where('event_type', 'failed')->exists())->toBeTrue();
});

it('rethrows transient cloudflare smtp failures so the queue can retry', function () {
    [$workspace, $project, $source, $token, $spy] = cloudflareProjectFixture();

    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Transient failure',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();
    $spy->failWith = new TransportException('Connection could not be established with host "smtp.mx.cloudflare.net".');

    expect(fn () => (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class)))
        ->toThrow(TransportException::class);

    expect($email->fresh()->status)->toBe('sending');
});

it('redacts smtp auth credentials from stored transcripts', function () {
    $provider = app(CloudflareEmailProvider::class);

    $transcript = implode("\r\n", [
        '< 220 mx.cloudflare.net Cloudflare Email ESMTP Service ready',
        '> EHLO [127.0.0.1]',
        '< 250-AUTH PLAIN LOGIN',
        '> AUTH LOGIN',
        '< 334 VXNlcm5hbWU6',
        '> YXBpX3Rva2Vu',
        '< 334 UGFzc3dvcmQ6',
        '> c3VwZXItc2VjcmV0LXRva2Vu',
        '< 235 2.7.0 Authentication successful',
        '> MAIL FROM:<notifications@mail.example.com>',
        '< 250 2.1.0 Ok',
        '< 250 2.0.0 Ok <queue-id-123@mail.example.com>',
    ]);

    $sanitized = $provider->sanitizeSmtpTranscript($transcript);

    expect($sanitized)->not->toContain('YXBpX3Rva2Vu')
        ->not->toContain('c3VwZXItc2VjcmV0LXRva2Vu')
        ->toContain('> [redacted]')
        ->toContain('MAIL FROM:<notifications@mail.example.com>')
        ->toContain('250 2.0.0 Ok');
});
