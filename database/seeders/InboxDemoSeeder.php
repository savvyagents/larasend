<?php

namespace Database\Seeders;

use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Services\ThreadResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds a handful of realistic conversations into the first project so the
 * team inbox can be demoed without waiting for real inbound mail.
 *
 * Run with: php artisan db:seed --class=InboxDemoSeeder
 */
class InboxDemoSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()->orderBy('id')->first();

        if (! $project) {
            $this->command?->warn('No project found — run the main seeder first.');

            return;
        }

        $resolver = app(ThreadResolver::class);
        $owner = $project->workspace->users()->first();
        $inboxAddress = 'support@'.($project->domains()->value('domain') ?: 'example.com');

        $conversations = [
            [
                'from' => ['maya@acme-corp.test', 'Maya Lin'],
                'subject' => 'Question about invoice #4821',
                'messages' => [
                    ['direction' => 'in', 'text' => "Hi there,\n\nInvoice #4821 shows the old billing address. Can you re-issue it with our new one?\n\nThanks,\nMaya", 'age' => '2 days'],
                    ['direction' => 'out', 'text' => "Hi Maya,\n\nOf course — I've re-issued invoice #4821 with the updated address. You should have it in your inbox now.\n\nBest,\nSupport", 'age' => '1 day'],
                    ['direction' => 'in', 'text' => "Got it, that was fast. Thank you!\n\nMaya", 'age' => '4 hours'],
                ],
            ],
            [
                'from' => ['dev@nordwind.test', 'Jonas Berg'],
                'subject' => 'Webhook signatures — which header?',
                'messages' => [
                    ['direction' => 'in', 'text' => "Hey,\n\nWe're integrating the inbound webhook and can't find the signature header in the docs. Is it Larasend-Signature?\n\nJonas", 'age' => '7 hours'],
                ],
            ],
            [
                'from' => ['ops@brightscale.test', 'Priya Nair'],
                'subject' => 'Upgrade to annual plan',
                'messages' => [
                    ['direction' => 'in', 'text' => "Hello,\n\nWe'd like to move from monthly to annual billing for our workspace. What's the process?\n\nPriya", 'age' => '30 hours'],
                ],
                'note' => 'Priya is the decision maker — sales already sent the annual quote on Tuesday.',
            ],
        ];

        foreach ($conversations as $conversation) {
            [$fromEmail, $fromName] = $conversation['from'];
            $lastInbound = null;

            foreach ($conversation['messages'] as $index => $message) {
                $at = Carbon::parse('-'.$message['age']);

                if ($message['direction'] === 'in') {
                    $lastInbound = $this->receiveInbound(
                        $project,
                        $resolver,
                        fromEmail: $fromEmail,
                        fromName: $fromName,
                        to: $inboxAddress,
                        subject: ($index > 0 ? 'Re: ' : '').$conversation['subject'],
                        text: $message['text'],
                        inReplyTo: $lastInbound?->message_id,
                        at: $at,
                    );
                } else {
                    $this->sendOutbound(
                        $project,
                        $resolver,
                        from: $inboxAddress,
                        to: $fromEmail,
                        subject: 'Re: '.$conversation['subject'],
                        text: $message['text'],
                        inReplyTo: $lastInbound?->message_id,
                        at: $at,
                    );
                }
            }

            if (isset($conversation['note']) && $owner && $lastInbound?->thread) {
                $lastInbound->thread->notes()->create([
                    'user_id' => $owner->id,
                    'body' => $conversation['note'],
                ]);
            }
        }

        $this->command?->info('Seeded '.count($conversations).' demo conversations into "'.$project->name.'".');
    }

    private function receiveInbound(
        Project $project,
        ThreadResolver $resolver,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $text,
        ?string $inReplyTo,
        Carbon $at,
    ): InboundEmail {
        $publicId = 'inb_'.Str::lower(Str::random(20));
        $messageId = Str::lower(Str::random(12)).'@'.Str::after($fromEmail, '@');
        $mime = implode("\r\n", [
            "From: {$fromName} <{$fromEmail}>",
            "To: {$to}",
            "Subject: {$subject}",
            "Message-ID: <{$messageId}>",
            'Content-Type: text/plain; charset=utf-8',
            '',
            $text,
        ]);
        $mimePath = "inbound/{$project->id}/{$publicId}.eml";
        Storage::disk('local')->put($mimePath, $mime);

        $inbound = InboundEmail::query()->create([
            'public_id' => $publicId,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'source_id' => $project->sources()->value('id'),
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to_email' => $to,
            'subject' => $subject,
            'text' => $text,
            'headers' => $inReplyTo ? ['In-Reply-To' => "<{$inReplyTo}>"] : [],
            'attachments' => [],
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'mime_disk' => 'local',
            'mime_path' => $mimePath,
            'mime_size' => strlen($mime),
            'received_at' => $at,
        ]);

        $resolver->attachInbound($inbound);

        return $inbound;
    }

    private function sendOutbound(
        Project $project,
        ThreadResolver $resolver,
        string $from,
        string $to,
        string $subject,
        string $text,
        ?string $inReplyTo,
        Carbon $at,
    ): Email {
        $publicId = 'email_'.Str::random(24);
        $mime = implode("\r\n", [
            "From: {$from}",
            "To: {$to}",
            "Subject: {$subject}",
            'Content-Type: text/plain; charset=utf-8',
            '',
            $text,
        ]);
        $mimePath = "emails/{$project->id}/{$publicId}.eml";
        Storage::disk('local')->put($mimePath, $mime);

        $email = Email::query()->create([
            'public_id' => $publicId,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'source_id' => $project->sources()->value('id'),
            'environment' => 'prod',
            'status' => 'delivered',
            'from_email' => $from,
            'subject' => $subject,
            'text' => $text,
            'mime_disk' => 'local',
            'mime_path' => $mimePath,
            'mime_size' => strlen($mime),
            'headers' => $inReplyTo ? ['In-Reply-To' => "<{$inReplyTo}>"] : [],
            'tags' => [],
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $email->recipients()->create(['type' => 'to', 'email' => $to]);

        $resolver->attachOutbound($email);

        return $email;
    }
}
