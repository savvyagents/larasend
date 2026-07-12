<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\InboundEmail;
use App\Services\ThreadResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('larasend:thread-backfill')]
#[Description('Group existing outbound and inbound emails into conversation threads')]
class ThreadBackfillCommand extends Command
{
    public function handle(ThreadResolver $threads): int
    {
        // Chronological order matters: replies must find the thread their
        // original started, so the whole history replays oldest-first.
        $messages = collect()
            ->concat(Email::query()->whereNull('thread_id')->get()->map(fn (Email $email) => ['at' => $email->created_at, 'model' => $email]))
            ->concat(InboundEmail::query()->whereNull('thread_id')->get()->map(fn (InboundEmail $inbound) => ['at' => $inbound->received_at, 'model' => $inbound]))
            ->sortBy('at')
            ->values();

        $count = 0;

        foreach ($messages as $entry) {
            $entry['model'] instanceof Email
                ? $threads->attachOutbound($entry['model'])
                : $threads->attachInbound($entry['model']);
            $count++;
        }

        $this->info("Threaded {$count} message(s).");

        return self::SUCCESS;
    }
}
