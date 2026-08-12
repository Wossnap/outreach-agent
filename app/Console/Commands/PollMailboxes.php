<?php

namespace App\Console\Commands;

use App\Jobs\PollMailboxJob;
use App\Models\Mailbox;
use Illuminate\Console\Command;

class PollMailboxes extends Command
{
    protected $signature = 'gmail:poll-mailboxes';

    protected $description = 'Queue an inbox poll (replies/bounces) for every connected mailbox';

    public function handle(): int
    {
        // Paused mailboxes still poll: replies must stop sequences even while
        // sending is on hold. Only disconnected mailboxes are skipped.
        $ids = Mailbox::query()
            ->whereIn('status', [Mailbox::STATUS_ACTIVE, Mailbox::STATUS_PAUSED, Mailbox::STATUS_ERROR])
            ->whereNotNull('google_refresh_token')
            ->pluck('id');

        foreach ($ids as $id) {
            PollMailboxJob::dispatch($id);
        }

        $this->info("Queued polls for {$ids->count()} mailboxes.");

        return self::SUCCESS;
    }
}
