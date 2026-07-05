<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Template;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->orderBy('id')->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => 'Vijay Tupakula',
                'email' => 'test@example.com',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ]);
        }

        $workspace = $user->workspaces()->with('projects')->first();

        if (! $workspace) {
            $workspace = Workspace::query()->updateOrCreate(
                ['slug' => 'larasend'],
                [
                    'owner_id' => $user->id,
                    'name' => 'Larasend',
                ],
            );
        } else {
            $workspace->forceFill([
                'owner_id' => $user->id,
            ])->save();
        }

        $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => 'owner'],
        ]);

        $project = $workspace->projects()->where('slug', 'harborlight')->first()
            ?? $workspace->projects()->first()
            ?? Project::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'harborlight',
                'slug' => 'harborlight',
                'default_environment' => 'prod',
            ]);

        $project->forceFill([
            'name' => 'harborlight',
            'slug' => 'harborlight',
            'default_environment' => 'prod',
        ])->save();

        $secondaryProject = $workspace->projects()->updateOrCreate(
            ['slug' => 'northwind'],
            [
                'name' => 'northwind',
                'default_environment' => 'prod',
            ],
        );

        foreach ([$project, $secondaryProject] as $demoProject) {
            $this->resetDemoData($demoProject);

            $domains = $this->seedDomains($demoProject);
            $source = $this->seedSource($demoProject, $domains['mails.savvyagents.ai']->id);
            $templates = $this->seedTemplates($demoProject);
            $this->seedApiKeys($demoProject, $source);
            $this->seedWebhooks($demoProject);
            $this->seedEmails($workspace, $demoProject, $source, $templates);
        }
    }

    private function resetDemoData(Project $project): void
    {
        $project->emails()->get()->each->delete();
        $project->webhookEndpoints()->get()->each->delete();
    }

    /**
     * @return array<string, Domain>
     */
    private function seedDomains(Project $project): array
    {
        $records = [
            [
                'type' => 'CNAME',
                'name' => 'iee43wogfoxhswwn._domainkey.mails.savvyagents.ai',
                'value' => 'iee43wogfoxhswwn.dkim.amazonses.com',
                'status' => 'ok',
            ],
            [
                'type' => 'CNAME',
                'name' => 'j1hbuapuqiya6yy9._domainkey.mails.savvyagents.ai',
                'value' => 'j1hbuapuqiya6yy9.dkim.amazonses.com',
                'status' => 'ok',
            ],
            [
                'type' => 'CNAME',
                'name' => '4ujvmzpurrlsoqet._domainkey.mails.savvyagents.ai',
                'value' => '4ujvmzpurrlsoqet.dkim.amazonses.com',
                'status' => 'ok',
            ],
            [
                'type' => 'TXT',
                'name' => 'mails.savvyagents.ai',
                'value' => 'v=spf1 include:amazonses.com ~all',
                'status' => 'ok',
            ],
            [
                'type' => 'TXT',
                'name' => '_dmarc.mails.savvyagents.ai',
                'value' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@savvyagents.ai',
                'status' => 'ok',
            ],
            [
                'type' => 'MX',
                'name' => 'bounces.mails.savvyagents.ai',
                'value' => '10 feedback-smtp.us-east-1.amazonses.com',
                'status' => 'ok',
            ],
        ];

        $domains = [];

        foreach ([
            ['mails.savvyagents.ai', 'verified', $records],
            ['larasend.app', 'verified', $this->recordsFor('larasend.app', 'ok')],
            ['mail.harborlight.app', 'pending', $this->recordsFor('mail.harborlight.app', 'pending')],
        ] as [$domain, $status, $dnsRecords]) {
            $domains[$domain] = $project->domains()->updateOrCreate(
                ['domain' => $domain],
                [
                    'status' => $status,
                    'dns_records' => $dnsRecords,
                    'verified_at' => $status === 'verified' ? now() : null,
                ],
            );
        }

        return $domains;
    }

    /**
     * @return array<int, array{type: string, name: string, value: string, status: string}>
     */
    private function recordsFor(string $domain, string $status): array
    {
        return [
            [
                'type' => 'CNAME',
                'name' => 'hsk1._domainkey.'.$domain,
                'value' => 'hsk1.dkim.amazonses.com',
                'status' => $status,
            ],
            [
                'type' => 'CNAME',
                'name' => 'hsk2._domainkey.'.$domain,
                'value' => 'hsk2.dkim.amazonses.com',
                'status' => $status,
            ],
            [
                'type' => 'CNAME',
                'name' => 'hsk3._domainkey.'.$domain,
                'value' => 'hsk3.dkim.amazonses.com',
                'status' => $status,
            ],
            [
                'type' => 'TXT',
                'name' => $domain,
                'value' => 'v=spf1 include:amazonses.com ~all',
                'status' => $status,
            ],
            [
                'type' => 'TXT',
                'name' => '_dmarc.'.$domain,
                'value' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@'.$domain,
                'status' => $status,
            ],
        ];
    }

    private function seedSource(Project $project, int $domainId): Source
    {
        return $project->sources()->updateOrCreate(
            ['environment' => 'prod'],
            [
                'domain_id' => $domainId,
                'name' => 'Savvy Agents Transactional',
                'ses_region' => 'us-east-1',
                'ses_configuration_set' => 'larasend-prod',
                'default_from_name' => 'Savvy Agents',
                'default_from_email' => 'notifications@mails.savvyagents.ai',
                'webhook_token' => (string) Str::uuid(),
                'retention_days' => 90,
                'monthly_quota' => 500000,
                'max_send_rate' => 200,
                'last_quota_checked_at' => now(),
                'last_quota' => [
                    'sentLast24Hours' => 14280,
                    'max24HourSend' => 500000,
                    'maxSendRate' => 200,
                ],
            ],
        );
    }

    /**
     * @return array<string, Template>
     */
    private function seedTemplates(Project $project): array
    {
        $templateRows = [
            ['tx.receipt.v3', 'Receipt', 'Your receipt from Northwind - #{{ invoice }}', $this->receiptHtml(), 'Thanks for your order, {{ name }}.', ['name', 'invoice']],
            ['auth.password-reset.v1', 'Password reset', 'Reset your password', $this->simpleHtml('Reset your password', 'Use the secure link below to finish resetting access.'), 'Reset your password.', ['name']],
            ['digest.weekly.v1', 'Weekly digest', 'Weekly deliverability digest', $this->simpleHtml('Your weekly deliverability digest', 'Delivery, bounce, and complaint trends are ready.'), 'Your weekly deliverability digest.', ['name']],
            ['tx.invoice.v4', 'Invoice', 'Invoice #{{ invoice }} is ready', $this->simpleHtml('Invoice ready', 'Your invoice is attached and ready for review.'), 'Invoice ready.', ['invoice']],
            ['mk.newsletter.may', 'May newsletter', 'May product updates', $this->simpleHtml('May product updates', 'A concise roundup of what shipped this month.'), 'May product updates.', ['name']],
            ['sys.alert.webhook', 'Webhook alert', 'Webhook delivery alert', $this->simpleHtml('Webhook delivery alert', 'One webhook endpoint needs attention.'), 'Webhook delivery alert.', ['endpoint']],
            ['onb.welcome.v6', 'Welcome', 'Welcome to Larasend', $this->simpleHtml('Welcome to Larasend', 'Your transactional email workspace is ready.'), 'Welcome to Larasend.', ['name']],
            ['tx.form-submit.v1', 'Form submission', 'Form submission received', $this->simpleHtml('Form submission received', 'A new form submission was routed to your team.'), 'Form submission received.', ['name']],
            ['auth.otp.v1', 'One-time passcode', 'Your one-time passcode', $this->simpleHtml('Your one-time passcode', 'Use this code to finish signing in: 492118.'), 'Your one-time passcode is 492118.', ['code']],
            ['mk.onboarding.p1', 'Onboarding', 'Welcome aboard', $this->simpleHtml('Welcome aboard', 'Your onboarding checklist is ready.'), 'Welcome aboard.', ['name']],
        ];

        $templates = [];

        foreach ($templateRows as [$slug, $name, $subject, $html, $text, $variables]) {
            $templates[$slug] = $project->templates()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'subject' => $subject,
                    'html' => $html,
                    'text' => $text,
                    'variables' => $variables,
                ],
            );
        }

        return $templates;
    }

    private function seedApiKeys(Project $project, Source $source): void
    {
        foreach ([
            ['Production - Harborlight', 'lsk_live_8f2a', 13],
            ['Production - server-eu', 'lsk_live_aa01', 20],
            ['Staging', 'lsk_test_3b14', 180],
            ['Marketing - Mailmerge', 'lsk_live_cc41', 960],
            ['CI - ephemeral', 'lsk_test_d011', 120],
        ] as [$name, $prefix, $minutesAgo]) {
            $project->apiKeys()->updateOrCreate(
                ['prefix' => $prefix],
                [
                    'source_id' => $source->id,
                    'name' => $name,
                    'key_hash' => hash('sha256', $prefix.'_demo_secret_'.$project->id),
                    'last_used_at' => now()->subMinutes($minutesAgo),
                ],
            );
        }

        if (! $project->apiKeys()->where('name', 'Development key')->exists()) {
            ApiKey::issue($project, 'Development key', $source);
        }
    }

    private function seedWebhooks(Project $project): void
    {
        $endpoints = [
            ['https://api.harborlight.app/webhooks/larasend', ['delivery', 'bounce', 'complaint', 'open', 'click', 'suppress'], 'active', 99.94],
            ['https://kettleworks.net/hooks/ses', ['bounce', 'complaint'], 'active', 88.21],
            ['https://hooks.zapier.com/hooks/catch/9824/abc12def', ['delivery', 'open', 'click'], 'active', 100.00],
            ['https://api.dovetail.studio/sys/email-events', ['complaint', 'suppress'], 'paused', 99.81],
        ];

        foreach ($endpoints as $endpointIndex => [$url, $events, $status, $successRate]) {
            $issued = WebhookEndpoint::issue($project, $url, $events, $status);
            $endpoint = $issued['endpoint'];
            $endpoint->forceFill([
                'last_delivered_at' => now()->subMinutes(10 + ($endpointIndex * 13)),
            ])->save();

            for ($delivery = 0; $delivery < 14; $delivery++) {
                $ok = $status === 'paused' || $successRate >= 99 || $delivery % 5 !== 0;
                $endpoint->deliveries()->create([
                    'public_id' => 'whd_'.Str::lower(Str::random(8)),
                    'event_type' => $events[$delivery % count($events)],
                    'http_status' => $ok ? 200 : 503,
                    'latency_ms' => $ok ? 70 + ($delivery * 11) : 5000,
                    'status' => $ok ? 'ok' : 'fail',
                    'payload' => ['message_id' => 'msg_'.Str::upper(Str::random(8))],
                    'response_body' => $ok ? 'ok' : 'Service unavailable',
                    'delivered_at' => now()->subMinutes(10 + $delivery + ($endpointIndex * 8)),
                ]);
            }
        }
    }

    /**
     * @param  array<string, Template>  $templates
     */
    private function seedEmails(Workspace $workspace, Project $project, Source $source, array $templates): void
    {
        $people = $this->people();
        $templateSlugs = array_keys($templates);
        $statuses = [
            'clicked',
            'opened',
            'delivered',
            'delivered',
            'clicked',
            'delivered',
            'bounced',
            'delivered',
            'opened',
            'delivered',
            'complained',
            'clicked',
            'sent',
            'delivered',
            'opened',
        ];

        for ($index = 0; $index < 180; $index++) {
            $person = $people[$index % count($people)];
            $templateSlug = $templateSlugs[$index % count($templateSlugs)];
            $template = $templates[$templateSlug];
            $status = $statuses[$index % count($statuses)];
            $createdAt = Carbon::now()->subMinutes(8 + ($index * 17));
            $subject = $this->subjectFor($templateSlug, $index);

            $email = Email::query()->create([
                'public_id' => 'email_'.Str::random(24),
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'source_id' => $source->id,
                'template_id' => $template->id,
                'environment' => 'prod',
                'status' => $status,
                'ses_message_id' => 'msg_'.Str::upper(Str::random(12)),
                'from_email' => 'notifications@mails.savvyagents.ai',
                'from_name' => 'Savvy Agents',
                'subject' => $subject,
                'html' => $templateSlug === 'tx.receipt.v3' ? $this->receiptHtml($person[0], 'INV-'.(4800 + $index)) : $template->html,
                'text' => $template->text,
                'mime_disk' => 'local',
                'mime_path' => null,
                'mime_size' => 8400 + ($index * 73),
                'headers' => [
                    'X-Larasend-Template' => $templateSlug,
                    'X-SES-Configuration-Set' => 'larasend-prod',
                ],
                'tags' => [
                    'template' => $templateSlug,
                    'tenant' => $person[2],
                    'campaign' => $index % 6 === 0 ? 'onboarding' : 'transactional',
                ],
                'sent_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $email->recipients()->create([
                'type' => 'to',
                'name' => $person[0],
                'email' => $person[1],
            ]);

            if ($index % 13 === 0) {
                $email->attachments()->create([
                    'filename' => 'invoice-'.(1040 + $index).'.pdf',
                    'content_type' => 'application/pdf',
                    'size' => 108000 + ($index * 80),
                    'disposition' => 'attachment',
                ]);
            }

            $this->seedEmailEvents($email, $source, $status, $person[1], $createdAt, $index);
        }
    }

    private function seedEmailEvents(Email $email, Source $source, string $status, string $recipient, Carbon $createdAt, int $index): void
    {
        $email->events()->create([
            'source_id' => $source->id,
            'event_type' => 'send',
            'ses_message_id' => $email->ses_message_id,
            'recipient' => $recipient,
            'payload' => ['demo' => true],
            'occurred_at' => $createdAt,
        ]);

        if (in_array($status, ['sent'], true)) {
            return;
        }

        if (in_array($status, ['delivered', 'opened', 'clicked'], true)) {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'delivery',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'payload' => ['processing_time_millis' => 418 + $index],
                'occurred_at' => $createdAt->copy()->addMinute(),
            ]);
        }

        if (in_array($status, ['opened', 'clicked', 'complained'], true)) {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'open',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 Chrome/147.0',
                'ip_address' => '192.0.2.'.(($index % 200) + 1),
                'payload' => ['demo' => true],
                'occurred_at' => $createdAt->copy()->addMinutes(2),
            ]);
        }

        if ($status === 'clicked') {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'click',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'url' => 'https://app.harborlight.io/orders/'.(4800 + $index),
                'user_agent' => 'Mozilla/5.0 AppleWebKit/537.36 Chrome/147.0',
                'ip_address' => '198.51.100.'.(($index % 180) + 1),
                'payload' => ['link' => 'primary_cta'],
                'occurred_at' => $createdAt->copy()->addMinutes(4),
            ]);
        }

        if ($status === 'bounced') {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'bounce',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'payload' => [
                    'bounceType' => $index % 3 === 0 ? 'Transient' : 'Permanent',
                    'diagnosticCode' => $index % 3 === 0 ? 'Mailbox full' : 'No such recipient',
                    'smtpResponse' => $index % 3 === 0 ? '452 4.2.2' : '550 5.1.1',
                ],
                'occurred_at' => $createdAt->copy()->addMinutes(3),
            ]);
        }

        if ($status === 'complained') {
            $email->events()->create([
                'source_id' => $source->id,
                'event_type' => 'complaint',
                'ses_message_id' => $email->ses_message_id,
                'recipient' => $recipient,
                'payload' => ['complaintFeedbackType' => 'abuse'],
                'occurred_at' => $createdAt->copy()->addMinutes(6),
            ]);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function people(): array
    {
        return [
            ['Maya Okafor', 'maya.okafor@northwind.io', 'northwind'],
            ['Darren Lin', 'darren.lin@vellum.dev', 'vellum'],
            ['Hexabrew Team', 'team@hexabrew.shop', 'hexabrew'],
            ['Oliver Brandt', 'oliver.brandt@tealforge.co', 'tealforge'],
            ['Lana Voss', 'lana@stillwater.dev', 'stillwater'],
            ['Kettleworks Helpdesk', 'helpdesk@kettleworks.io', 'kettleworks'],
            ['Ana del Pino', 'ana.delpino@gmail.com', 'northwind'],
            ['Northwind Support', 'support@northwind.io', 'northwind'],
            ['Felix Bauer', 'felix.bauer@finch.test', 'finch'],
            ['Tom Halpern', 'tom@rampath.gg', 'rampath'],
            ['Megan Ortiz', 'megan.ortiz@dove.test', 'dovetail'],
            ['Casey Tran', 'casey.t@harborlight.io', 'harborlight'],
            ['R. J. Patel', 'rj.patel@sokolelectric.com', 'sokol'],
            ['Priya Nair', 'priya@northwind.io', 'northwind'],
            ['Elliot Hayes', 'elliot@kestrelpath.io', 'kestrel'],
            ['Sofia Mendes', 'sofia@dovetail.studio', 'dovetail'],
            ['Marcus Lee', 'marcus@oldhouseretail.net', 'oldhouse'],
            ['Avery Stone', 'avery@trailmarkbike.com', 'trailmark'],
        ];
    }

    private function subjectFor(string $templateSlug, int $index): string
    {
        return match ($templateSlug) {
            'tx.receipt.v3' => 'Your receipt from Northwind - #INV-'.(4800 + $index),
            'tx.invoice.v4' => 'Invoice #'.(1040 + $index).' is ready',
            'mk.newsletter.may' => 'May newsletter',
            'sys.alert.webhook' => 'Webhook delivery alert',
            'onb.welcome.v6' => 'Welcome to Larasend',
            'tx.form-submit.v1' => 'Form submission received',
            'auth.otp.v1' => 'Your one-time passcode',
            'mk.onboarding.p1' => 'Welcome aboard',
            'digest.weekly.v1' => 'Weekly deliverability digest',
            default => 'Reset your password',
        };
    }

    private function simpleHtml(string $heading, string $body): string
    {
        return <<<HTML
<div style="font-family: Inter, Arial, sans-serif; color: #18181b; background: #fbfaf7; padding: 40px;">
    <p style="letter-spacing: 0.24em; color: #71717a; font-size: 12px;">LARASEND</p>
    <h1 style="font-size: 28px; margin: 28px 0 12px;">{$heading}</h1>
    <p style="font-size: 16px; line-height: 1.6; color: #52525b;">{$body}</p>
    <a style="display:inline-block;background:#2dd4bf;color:#082f2c;padding:12px 18px;border-radius:6px;text-decoration:none;font-weight:700;margin-top:20px;">Open dashboard</a>
</div>
HTML;
    }

    private function receiptHtml(string $name = 'Maya', string $invoice = 'INV-4821'): string
    {
        return <<<HTML
<div style="font-family: Inter, Arial, sans-serif; color: #1f2328; background: #f4f3ef; padding: 44px;">
    <p style="letter-spacing: 0.28em; color: #7d807c; font-size: 12px;">NORTHWIND</p>
    <h1 style="font-size: 28px; margin: 32px 0 16px;">Thanks for your order, {$name}.</h1>
    <p style="font-size: 18px; color: #6a6d70;">Order #{$invoice} - placed May 10, 2026</p>
    <div style="border: 1px solid #d5d2ca; border-radius: 8px; padding: 24px; margin: 28px 0; background: #f8f7f3;">
        <p>Saltspring chef's knife <strong style="float:right;">$184.00</strong></p>
        <p>Honing rod, 10&quot; <strong style="float:right;">$42.00</strong></p>
        <p>Linen apron, natural <strong style="float:right;">$56.00</strong></p>
        <hr>
        <p>Total charged <strong style="float:right;">$290.00</strong></p>
    </div>
    <a style="display:inline-block;background:#17a67f;color:white;padding:16px 28px;border-radius:6px;text-decoration:none;font-weight:700;">View order</a>
</div>
HTML;
    }
}
