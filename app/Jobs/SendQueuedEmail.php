<?php

namespace App\Jobs;

use App\Events\EmailActivityUpdated;
use App\Models\Email;
use App\Services\Providers\EmailProviderFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendQueuedEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(public int $emailId) {}

    public function handle(EmailProviderFactory $providers): void
    {
        $email = Email::query()->with(['source', 'recipients'])->findOrFail($this->emailId);

        // 'sending' is allowed through so a retry can recover an email whose
        // previous attempt died mid-flight (worker crash, timeout).
        if (! in_array($email->status, ['queued', 'sending'], true)) {
            return;
        }

        $email->forceFill(['status' => 'sending'])->save();
        EmailActivityUpdated::dispatch($email->fresh());

        try {
            $mime = $email->mime_path && Storage::disk($email->mime_disk)->exists($email->mime_path)
                ? Storage::disk($email->mime_disk)->get($email->mime_path)
                : null;

            if (! is_string($mime) || $mime === '') {
                throw new \RuntimeException("Stored MIME content is missing for {$email->public_id}.");
            }

            $result = $providers->forSource($email->source)->sendRawEmail($email->source, $mime, [
                'from' => $email->from_email,
                'recipients' => $email->recipients->pluck('email')->all(),
            ]);
        } catch (Throwable $exception) {
            // Stay in 'sending' so the queue's remaining attempts get past the
            // status guard above; failed() marks it failed after the last one.
            EmailActivityUpdated::dispatch($email->fresh());

            throw $exception;
        }

        $email->forceFill([
            'status' => 'sent',
            'ses_message_id' => $result['message_id'],
            'sent_at' => now(),
        ])->save();

        $email->events()->create([
            'source_id' => $email->source_id,
            'event_type' => 'send',
            'ses_message_id' => $result['message_id'],
            'payload' => $result['response'],
            'occurred_at' => now(),
        ]);

        EmailActivityUpdated::dispatch($email->fresh());
    }

    public function failed(Throwable $exception): void
    {
        $email = Email::query()->find($this->emailId);

        if (! $email) {
            return;
        }

        $email->forceFill(['status' => 'failed'])->save();

        $email->events()->create([
            'source_id' => $email->source_id,
            'event_type' => 'failed',
            'payload' => ['error' => $exception->getMessage()],
            'occurred_at' => now(),
        ]);

        EmailActivityUpdated::dispatch($email->fresh());
    }
}
