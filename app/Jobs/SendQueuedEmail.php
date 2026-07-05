<?php

namespace App\Jobs;

use App\Events\EmailActivityUpdated;
use App\Models\Email;
use App\Services\SesV2Client;
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

    public function handle(SesV2Client $sesClient): void
    {
        $email = Email::query()->with('source')->findOrFail($this->emailId);

        if ($email->status !== 'queued') {
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

            $result = $sesClient->sendRawEmail($email->source, $mime);
        } catch (Throwable $exception) {
            $email->forceFill(['status' => 'failed'])->save();
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
        EmailActivityUpdated::dispatch($email->fresh());
    }
}
