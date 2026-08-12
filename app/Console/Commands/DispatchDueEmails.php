<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailJob;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchDueEmails extends Command
{
    protected $signature = 'outreach:dispatch-due-emails';

    protected $description = 'Claim scheduled messages whose slot has arrived and dispatch send jobs';

    public function handle(): int
    {
        $due = Message::query()
            ->where('status', Message::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->with('mailbox')
            ->orderBy('scheduled_at')
            ->limit(200)
            ->get();

        $dispatched = 0;

        foreach ($due as $message) {
            if (! $message->mailbox || ! $message->mailbox->isSendable()) {
                // Leave it scheduled: un-pausing the mailbox lets it flow again.
                Log::warning("Skipping message #{$message->id}: mailbox not sendable", [
                    'mailbox' => $message->mailbox?->email,
                    'status' => $message->mailbox?->status,
                ]);

                continue;
            }

            // Atomic claim: the conditional UPDATE means two overlapping runs
            // (or a second worker) can never both dispatch the same message.
            $claimed = DB::table('messages')
                ->where('id', $message->id)
                ->where('status', Message::STATUS_SCHEDULED)
                ->update([
                    'status' => Message::STATUS_SENDING,
                    'sending_started_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 1) {
                SendEmailJob::dispatch($message->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} of {$due->count()} due messages.");

        return self::SUCCESS;
    }
}
