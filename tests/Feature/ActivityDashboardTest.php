<?php

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Support\ProjectContext;
use Illuminate\Support\Str;

it('routes incomplete first-run users to onboarding from dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/onboarding');
});

it('renders the activity dashboard for authenticated users', function () {
    $user = User::factory()->create();
    seedActivityDashboardData($user);

    $this->actingAs($user)
        ->get('/activity')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->has('project')
            ->has('metrics', 6)
            ->where('metrics', fn ($metrics) => collect($metrics)->every(fn (array $metric) => $metric['delta'] !== 'live'))
            ->has('suppressions', 2)
            ->where('quota.limit', null)
            ->has('emails', 12)
            ->where('emails', fn ($emails) => collect($emails)->contains(fn (array $email) => $email['subject'] === 'Your receipt from Northwind - #INV-4821'
                && str_contains($email['recipient'], '+ 1 more')
                && $email['recipient'] === 'maya.okafor@northwind.io, ops@kindsmile.test + 1 more'
                && $email['recipientEmails'] === 'maya.okafor@northwind.io, ops@kindsmile.test, reports@kindsmile.test'
                && $email['recipientCount'] === 3
                && $email['to'] === 'Maya Okafor <maya.okafor@northwind.io>, Kind Smile Ops <ops@kindsmile.test>, Kind Smile Reports <reports@kindsmile.test>'
                && $email['cc'] === 'Manager <manager@kindsmile.test>'))
            ->where('emails.0.sesMessageId', fn (?string $messageId) => filled($messageId))
            ->where('emails.0.headers.X-Larasend-Test', 'true')
            ->where('emails.0.previewUrl', fn (string $url) => str_contains($url, '/emails/') && str_ends_with($url, '/preview'))
        );
});

it('calculates activity metric deltas against the previous matching period', function () {
    $user = User::factory()->create();
    $project = seedActivityDashboardData($user);
    $source = $project->sources()->firstOrFail();

    foreach (range(1, 6) as $index) {
        $email = Email::create([
            'public_id' => 'email_prior_'.$index,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'source_id' => $source->id,
            'environment' => $source->environment,
            'status' => 'delivered',
            'from_email' => 'receipts@larasend.app',
            'subject' => 'Prior period '.$index,
            'created_at' => now()->subDays(15),
            'updated_at' => now()->subDays(15),
        ]);

        $email->forceFill([
            'created_at' => now()->subDays(15),
            'updated_at' => now()->subDays(15),
        ])->save();
    }

    $this->actingAs($user)
        ->get('/activity?range=14d')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.0.label', 'Sent')
            ->where('metrics.0.value', '12')
            ->where('metrics.0.delta', '+100%')
            ->where('metrics.0.trend', 'up')
            ->where('metrics.0.tone', 'good')
        );
});

it('renders configuration routes with real project data', function () {
    $user = User::factory()->create();
    seedActivityDashboardData($user);

    $this->actingAs($user)
        ->get('/templates')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'templates')
            ->has('templates', 1)
            ->has('domains', 1)
            ->has('webhooks', 2)
            ->has('apiKeys', 1)
        );
});

it('allows production instance role sources to open the send page', function () {
    $user = User::factory()->create();
    $project = seedActivityDashboardData($user);

    $project->sources()->firstOrFail()->forceFill([
        'last_quota' => [
            'Max24HourSend' => 50000,
            'MaxSendRate' => 200,
            'SentLast24Hours' => 25,
        ],
        'last_quota_checked_at' => now(),
    ])->save();

    $this->app->detectEnvironment(fn () => 'production');
    $this->withoutVite();

    try {
        $this->actingAs($user)
            ->get('/send')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('Activity')
                ->where('section', 'send')
                ->where('source.has_aws_credentials', false)
                ->where('source.uses_instance_role', true)
                ->where('source.can_send', true)
            );
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});

it('renders identities as selectable identity records with dns payloads', function () {
    $user = User::factory()->create();
    seedActivityDashboardData($user);

    $this->actingAs($user)
        ->get('/identities')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'identities')
            ->has('domains', 1)
            ->has('domains.0.id')
            ->where('domains.0.domain', 'larasend.app')
            ->has('domains.0.dns_records')
        );
});

it('renders bounces as a compact queue with bounce metrics', function () {
    $user = User::factory()->create();
    seedActivityDashboardData($user);

    $this->actingAs($user)
        ->get('/bounces')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'bounces')
            ->has('bounceMetrics', 5)
            ->has('bounceQueue', 1)
            ->where('bounceQueue.0.type', 'Hard')
            ->where('bounceQueue.0.smtp', '550 5.1.1')
        );
});

it('reveals a newly created api key once from the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['newApiKey' => 'ls_test_plaintext_token'])
        ->get('/api-keys')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'api-keys')
            ->where('newApiKey', 'ls_test_plaintext_token')
        );
});

it('renders webhook endpoints with delivery rows and the ses inbound url', function () {
    $user = User::factory()->create();
    seedActivityDashboardData($user);

    $this->actingAs($user)
        ->get('/webhooks')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'webhooks')
            ->has('webhookStats', 4)
            ->has('webhooks', 2)
            ->has('webhookDeliveries', 4)
            ->where('webhooks.0.status', 'failing')
            ->where('webhookDeliveries.0.status', 'fail')
            ->has('sesWebhookUrl')
        );
});

it('reveals a newly created webhook signing secret once from the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([
            'newWebhookEndpoint' => [
                'id' => 'wh_TEST',
                'url' => 'https://example.com/webhooks/larasend',
                'secret' => 'whsec_test_secret',
                'events' => ['delivery'],
            ],
        ])
        ->get('/webhooks')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'webhooks')
            ->where('newWebhookEndpoint.secret', 'whsec_test_secret')
        );
});

it('renders production setup checklist with onboarding links', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/setup')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('section', 'setup')
            ->has('setup.steps', 5)
            ->where('setup.steps.0.key', 'source')
            ->where('setup.steps.0.href', '/projects/my-project/source')
            ->where('setup.steps.2.key', 'webhook')
            ->where('setup.steps.2.complete', false)
            ->where('setup.steps.2.status', 'Webhook URL ready')
            ->where('setup.next_step.key', 'source')
            ->has('setup.webhook_url')
        );
});

it('only completes ses events setup after a real provider event is received', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();

    $source->forceFill([
        'default_from_email' => 'receipts@example.com',
        'aws_access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
        'aws_secret_access_key' => 'secret',
    ])->save();

    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'verified_at' => now(),
        'dns_records' => [],
    ]);

    ApiKey::issue($project, 'Production key', $source);

    $email = $project->emails()->create([
        'public_id' => 'email_eventcheck',
        'workspace_id' => $project->workspace_id,
        'source_id' => $source->id,
        'status' => 'sent',
        'from_email' => 'receipts@example.com',
        'subject' => 'Event check',
        'text' => 'Sent through Larasend.',
        'sent_at' => now(),
    ]);

    $email->events()->create([
        'source_id' => $source->id,
        'event_type' => 'send',
        'ses_message_id' => 'ses_eventcheck',
        'payload' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/setup')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('setup.steps.2.key', 'webhook')
            ->where('setup.steps.2.complete', false)
            ->where('setup.steps.2.status', 'Webhook URL ready')
            ->where('setup.next_step.key', 'webhook')
        );

    $email->events()->create([
        'source_id' => $source->id,
        'event_type' => 'delivery',
        'ses_message_id' => 'ses_eventcheck',
        'recipient' => 'customer@example.com',
        'payload' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/setup')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('setup.steps.2.key', 'webhook')
            ->where('setup.steps.2.complete', true)
            ->where('setup.steps.2.status', 'Events received')
            ->where('setup.next_step', null)
        );
});

it('redirects the send page to identities until SES sending is configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/send')
        ->assertRedirect('/projects/my-project/identities')
        ->assertInertiaFlash('toast');
});

it('filters activity by search query and exports csv', function () {
    $user = User::factory()->create();
    seedActivityDashboardData($user);

    $this->actingAs($user)
        ->get('/activity?q=maya&range=14d')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('filters.q', 'maya')
            ->where('filters.range', '14d')
            ->has('emails', 1)
            ->where('emails.0.recipient', 'maya.okafor@northwind.io, ops@kindsmile.test + 1 more')
        );

    $export = $this->actingAs($user)
        ->get('/activity/export?q=maya&range=14d')
        ->assertSuccessful()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($export->streamedContent())
        ->toContain('Message ID')
        ->toContain('maya.okafor@northwind.io');
});

it('allows workspace users to download raw mime content', function () {
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'mime-acme']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Project', 'slug' => 'project']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Prod', 'webhook_token' => 'mime-token']);
    $email = Email::create([
        'public_id' => 'email_mime',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'sent',
        'from_email' => 'receipts@example.com',
        'subject' => 'Receipt',
        'text' => 'Raw body',
    ]);

    $this->actingAs($user)
        ->get("/emails/{$email->public_id}/mime")
        ->assertSuccessful()
        ->assertSee('Raw body');
});

it('opens a sandboxed full email preview for workspace users', function () {
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'preview-acme']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Project', 'slug' => 'project']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Prod', 'webhook_token' => 'preview-token']);
    $email = Email::create([
        'public_id' => 'email_preview',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'sent',
        'from_email' => 'receipts@example.com',
        'from_name' => 'Receipts',
        'subject' => 'Designed receipt',
        'html' => '<div style="color:#155e75"><h1>Receipt</h1><p>Styled body</p></div>',
    ]);
    $email->recipients()->create(['type' => 'to', 'email' => 'maya@example.com', 'name' => 'Maya']);

    $this->actingAs($user)
        ->get("/emails/{$email->public_id}/preview")
        ->assertSuccessful()
        ->assertHeader('content-type', 'text/html; charset=UTF-8')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertSee('Designed receipt')
        ->assertSee('Styled body')
        ->assertSee('sandbox', false);
});

function seedActivityDashboardData(User $user): Project
{
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();

    $source->forceFill([
        'default_from_name' => 'Larasend Receipts',
        'default_from_email' => 'receipts@larasend.app',
    ])->save();

    $project->domains()->firstOrCreate(
        ['domain' => 'larasend.app'],
        [
            'status' => 'verified',
            'verified_at' => now(),
            'dns_records' => [
                ['type' => 'CNAME', 'name' => 'dkim._domainkey.larasend.app', 'value' => 'dkim.amazonses.com', 'status' => 'ok'],
            ],
        ],
    );

    $template = $project->templates()->firstOrCreate(
        ['slug' => 'tx.receipt.v3'],
        [
            'name' => 'Receipt',
            'subject' => 'Your receipt from Northwind - #{{ invoice }}',
            'html' => '<div><h1>Receipt</h1><p>Thanks for your order.</p></div>',
            'text' => 'Thanks for your order, {{ name }}.',
            'variables' => ['name', 'invoice'],
        ],
    );

    if (! $project->apiKeys()->exists()) {
        ApiKey::issue($project, 'Development key', $source);
    }

    if (! $project->webhookEndpoints()->exists()) {
        $healthy = WebhookEndpoint::issue($project, 'https://hooks.example.com/healthy', ['delivery', 'open'])['endpoint'];
        $failing = WebhookEndpoint::issue($project, 'https://hooks.example.com/failing', ['bounce', 'complaint'])['endpoint'];

        $healthy->deliveries()->createMany([
            ['public_id' => 'whd_'.Str::lower(Str::random(6)), 'event_type' => 'delivery', 'http_status' => 200, 'latency_ms' => 92, 'status' => 'ok', 'payload' => [], 'delivered_at' => now()->subMinutes(4)],
            ['public_id' => 'whd_'.Str::lower(Str::random(6)), 'event_type' => 'open', 'http_status' => 200, 'latency_ms' => 110, 'status' => 'ok', 'payload' => [], 'delivered_at' => now()->subMinutes(3)],
        ]);
        $healthy->forceFill(['last_delivered_at' => now()->subMinutes(3)])->save();

        $failing->deliveries()->createMany([
            ['public_id' => 'whd_'.Str::lower(Str::random(6)), 'event_type' => 'complaint', 'http_status' => 200, 'latency_ms' => 130, 'status' => 'ok', 'payload' => [], 'delivered_at' => now()->subMinutes(2)],
            ['public_id' => 'whd_'.Str::lower(Str::random(6)), 'event_type' => 'bounce', 'http_status' => 503, 'latency_ms' => 5000, 'status' => 'fail', 'payload' => [], 'response_body' => 'Service unavailable', 'delivered_at' => now()->subMinute()],
        ]);
        $failing->forceFill(['last_delivered_at' => now()->subMinute()])->save();
    }

    if ($project->emails()->exists()) {
        return $project;
    }

    $rows = [
        ['Maya Okafor', 'maya.okafor@northwind.io', 'clicked', 'Your receipt from Northwind - #INV-4821', 1, 1],
        ['Darren Lin', 'darren.lin@vellum.dev', 'opened', 'Reset your password', 1, 0],
        ['Hexabrew Team', 'team@hexabrew.shop', 'delivered', 'Weekly deliverability digest', 0, 0],
        ['Oliver Brandt', 'oliver.brandt@tealforge.co', 'opened', 'Invoice #1048 is ready', 1, 0],
        ['Lana Voss', 'lana@stillwater.dev', 'clicked', 'May newsletter', 1, 1],
        ['Kettleworks Helpdesk', 'helpdesk@kettleworks.io', 'delivered', 'Webhook alert', 0, 0],
        ['Ana del Pino', 'ana.delpino@gmail.com', 'bounced', 'Welcome to Larasend', 0, 0],
        ['Northwind Support', 'support@northwind.io', 'delivered', 'Form submission received', 0, 0],
        ['Felix Bauer', 'felix.bauer@finch.test', 'opened', 'Your order shipped', 1, 0],
        ['Tom Halpern', 'tom@rampath.gg', 'delivered', 'Your one-time passcode', 0, 0],
        ['Megan Ortiz', 'megan.ortiz@dove.test', 'complained', 'Issue update', 1, 0],
        ['Casey Tran', 'casey.t@harborlight.io', 'clicked', 'Welcome aboard', 1, 1],
    ];

    foreach ($rows as $index => [$name, $recipient, $status, $subject, $opens, $clicks]) {
        $createdAt = now()->subMinutes(5 + $index);
        $email = Email::create([
            'public_id' => 'email_'.Str::random(24),
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'source_id' => $source->id,
            'template_id' => $index === 0 ? $template->id : null,
            'environment' => $source->environment,
            'status' => $status,
            'ses_message_id' => 'msg_'.Str::upper(Str::random(8)),
            'from_email' => 'receipts@larasend.app',
            'from_name' => 'Larasend Receipts',
            'subject' => $subject,
            'html' => '<div><h1>'.$subject.'</h1><p>This is a stored email preview.</p></div>',
            'text' => 'Stored email preview for '.$subject,
            'headers' => ['X-Larasend-Test' => 'true'],
            'tags' => ['attempts' => 1],
            'sent_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $email->recipients()->create(['type' => 'to', 'name' => $name, 'email' => $recipient]);

        if ($index === 0) {
            $email->recipients()->create(['type' => 'to', 'name' => 'Kind Smile Ops', 'email' => 'ops@kindsmile.test']);
            $email->recipients()->create(['type' => 'to', 'name' => 'Kind Smile Reports', 'email' => 'reports@kindsmile.test']);
            $email->recipients()->create(['type' => 'cc', 'name' => 'Manager', 'email' => 'manager@kindsmile.test']);
        }

        $email->events()->create(['source_id' => $source->id, 'event_type' => 'send', 'ses_message_id' => $email->ses_message_id, 'payload' => [], 'occurred_at' => $createdAt]);

        if (in_array($status, ['delivered', 'opened', 'clicked'], true)) {
            $email->events()->create(['source_id' => $source->id, 'event_type' => 'delivery', 'ses_message_id' => $email->ses_message_id, 'recipient' => $recipient, 'payload' => [], 'occurred_at' => $createdAt->copy()->addMinute()]);
        }

        if ($status === 'bounced') {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'bounce',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'payload' => [
                    'bounce' => [
                        'bounceType' => 'Permanent',
                        'bouncedRecipients' => [[
                            'emailAddress' => $recipient,
                            'status' => '550 5.1.1',
                            'diagnosticCode' => 'No such recipient',
                        ]],
                    ],
                ],
                'occurred_at' => $createdAt->copy()->addMinute(),
            ]);

            Suppression::query()->create([
                'workspace_id' => $email->workspace_id,
                'project_id' => $email->project_id,
                'source_id' => $email->source_id,
                'email_id' => $email->id,
                'email' => $recipient,
                'reason' => 'hard_bounce',
                'event_type' => 'bounce',
            ]);
        }

        if ($status === 'complained') {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'complaint',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'payload' => [
                    'complaint' => [
                        'complainedRecipients' => [[
                            'emailAddress' => $recipient,
                        ]],
                    ],
                ],
                'occurred_at' => $createdAt->copy()->addMinute(),
            ]);

            Suppression::query()->create([
                'workspace_id' => $email->workspace_id,
                'project_id' => $email->project_id,
                'source_id' => $email->source_id,
                'email_id' => $email->id,
                'email' => $recipient,
                'reason' => 'complaint',
                'event_type' => 'complaint',
            ]);
        }

        for ($open = 1; $open <= $opens; $open++) {
            $email->events()->create(['source_id' => $source->id, 'event_type' => 'open', 'ses_message_id' => $email->ses_message_id, 'recipient' => $recipient, 'payload' => [], 'occurred_at' => $createdAt->copy()->addMinutes($open + 1)]);
        }

        for ($click = 1; $click <= $clicks; $click++) {
            $email->events()->create(['source_id' => $source->id, 'event_type' => 'click', 'ses_message_id' => $email->ses_message_id, 'recipient' => $recipient, 'url' => 'https://example.com/order', 'payload' => [], 'occurred_at' => $createdAt->copy()->addMinutes($click + 3)]);
        }
    }

    return $project;
}
