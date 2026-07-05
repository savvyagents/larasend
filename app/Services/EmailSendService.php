<?php

namespace App\Services;

use App\Events\EmailActivityUpdated;
use App\Jobs\SendQueuedEmail;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Template;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailSendService
{
    public function __construct(
        private MimeMessageBuilder $mimeBuilder,
        private SesV2Client $sesClient,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(Project $project, ?Source $source, array $payload): Email
    {
        $source ??= $project->sources()->where('environment', $project->default_environment)->firstOrFail();
        $template = $this->resolveTemplate($project, $payload);
        $payload = $this->applyTemplate($payload, $template);
        $from = $payload['from'] ?? $source->default_from_email;
        $this->ensureSourceCanSend($project, $source, (string) $from);
        $this->ensureRecipientsAreSendable($project, $payload);

        return DB::transaction(function () use ($project, $source, $payload, $template, $from) {
            $publicId = 'email_'.Str::random(24);
            $mime = $this->mimeBuilder->build(
                from: $from,
                to: $payload['to'],
                cc: $payload['cc'] ?? [],
                bcc: $payload['bcc'] ?? [],
                replyTo: $payload['reply_to'] ?? null,
                subject: $payload['subject'],
                html: $payload['html'] ?? null,
                text: $payload['text'] ?? null,
                headers: $payload['headers'] ?? [],
                attachments: $payload['attachments'] ?? [],
            );

            $mimePath = "emails/{$project->id}/{$publicId}.eml";
            Storage::disk('local')->put($mimePath, $mime);
            $fromAddress = $this->mimeBuilder->splitAddress($from);

            $email = Email::create([
                'public_id' => $publicId,
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'source_id' => $source->id,
                'template_id' => $template?->id,
                'environment' => $source->environment,
                'status' => 'queued',
                'from_email' => $fromAddress['email'],
                'from_name' => $fromAddress['name'],
                'subject' => $payload['subject'],
                'html' => $payload['html'] ?? null,
                'text' => $payload['text'] ?? null,
                'mime_disk' => 'local',
                'mime_path' => $mimePath,
                'mime_size' => strlen($mime),
                'headers' => $payload['headers'] ?? [],
                'tags' => $payload['tags'] ?? [],
            ]);

            foreach (['to', 'cc', 'bcc'] as $type) {
                foreach ($payload[$type] ?? [] as $recipient) {
                    $address = $this->mimeBuilder->splitAddress($recipient);
                    $email->recipients()->create([
                        'type' => $type,
                        'email' => $address['email'],
                        'name' => $address['name'],
                    ]);
                }
            }

            foreach ($payload['attachments'] ?? [] as $attachment) {
                $email->attachments()->create([
                    'filename' => $attachment['filename'],
                    'content_type' => $attachment['content_type'] ?? 'application/octet-stream',
                    'size' => strlen(base64_decode($attachment['content'], strict: true) ?: ''),
                ]);
            }

            EmailActivityUpdated::dispatch($email);
            SendQueuedEmail::dispatch($email->id)->afterCommit();

            return $email->load(['recipients', 'events', 'attachments', 'source', 'template']);
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureSourceCanSend(Project $project, Source $source, string $from): void
    {
        if (blank($from) || ! $this->mimeBuilder->splitAddress($from)['email']) {
            throw ValidationException::withMessages([
                'from' => 'A valid sender address is required before sending.',
            ]);
        }

        if (! filled($source->aws_access_key_id) && ! app()->environment('production')) {
            throw ValidationException::withMessages([
                'send' => 'Connect SES credentials before sending email.',
            ]);
        }

        if (! $this->fromAddressUsesVerifiedDomain($project, $from)) {
            throw ValidationException::withMessages([
                'from' => 'The sender domain is not verified for this project.',
            ]);
        }

        if (! $this->hasFreshQuota($source)) {
            $this->refreshSourceQuota($source);
        }

        if ($this->complaintRateIsTooHigh($project)) {
            throw ValidationException::withMessages([
                'send' => 'Sending is paused because the 30 day complaint rate is above 0.1%.',
            ]);
        }
    }

    private function hasFreshQuota(Source $source): bool
    {
        return $source->last_quota_checked_at?->greaterThan(now()->subHours(6)) === true
            && filled($source->last_quota);
    }

    private function refreshSourceQuota(Source $source): void
    {
        try {
            $account = $this->sesClient->getAccount($source);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $source->forceFill([
            'last_quota' => $account['SendQuota'] ?? $account,
            'last_quota_checked_at' => now(),
        ])->save();
    }

    private function fromAddressUsesVerifiedDomain(Project $project, string $from): bool
    {
        $fromDomain = Str::lower(Str::after($this->mimeBuilder->splitAddress($from)['email'], '@'));

        if ($fromDomain === '') {
            return false;
        }

        return $project->domains()
            ->whereIn('status', ['verified', 'local'])
            ->pluck('domain')
            ->contains(function (string $domain) use ($fromDomain): bool {
                $domain = Str::lower($domain);

                return $fromDomain === $domain
                    || Str::endsWith($fromDomain, '.'.$domain)
                    || Str::endsWith($domain, '.'.$fromDomain);
            });
    }

    private function complaintRateIsTooHigh(Project $project): bool
    {
        $since = now()->subDays(30);
        $total = $project->emails()->where('created_at', '>=', $since)->count();

        if ($total < 100) {
            return false;
        }

        $complaints = $project->emails()
            ->where('created_at', '>=', $since)
            ->where('status', 'complained')
            ->count();

        return ($complaints / $total) >= 0.001;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function ensureRecipientsAreSendable(Project $project, array $payload): void
    {
        $recipients = collect(['to', 'cc', 'bcc'])
            ->flatMap(fn (string $type) => $payload[$type] ?? [])
            ->map(fn (string $recipient): string => Str::lower($this->mimeBuilder->splitAddress($recipient)['email']))
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $suppressed = $project->suppressions()
            ->whereIn('email', $recipients)
            ->pluck('email')
            ->map(fn (string $email): string => Str::lower($email))
            ->all();

        if ($suppressed === []) {
            return;
        }

        throw ValidationException::withMessages([
            'to' => 'This email includes suppressed recipients: '.implode(', ', $suppressed),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTemplate(Project $project, array $payload): ?Template
    {
        if (! isset($payload['template_id'])) {
            return null;
        }

        return $project->templates()
            ->where(function ($query) use ($payload) {
                $query->whereKey($payload['template_id'])->orWhere('slug', $payload['template_id']);
            })
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyTemplate(array $payload, ?Template $template): array
    {
        if (! $template) {
            return $payload;
        }

        $variables = $payload['variables'] ?? [];

        foreach (['subject', 'html', 'text'] as $field) {
            $value = $payload[$field] ?? $template->{$field};

            if (is_string($value)) {
                $payload[$field] = Str::of($value)->replaceMatches('/{{\s*([A-Za-z0-9_]+)\s*}}/', function (array $matches) use ($variables) {
                    return (string) Arr::get($variables, $matches[1], '');
                })->toString();
            }
        }

        return $payload;
    }
}
