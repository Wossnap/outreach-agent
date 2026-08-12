<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Mailbox;
use App\Services\Inbound\GmailInboxFetcher;
use App\Services\Inbound\InboundProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PollMailboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $mailboxId,
    ) {}

    public function handle(GmailInboxFetcher $fetcher, InboundProcessor $processor): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if (! $mailbox || ! $mailbox->google_refresh_token || $mailbox->status === Mailbox::STATUS_DISCONNECTED) {
            return;
        }

        foreach ($fetcher->fetchNew($mailbox) as $email) {
            $processor->process($mailbox, $email);
        }
    }

    public function failed(Throwable $exception): void
    {
        ActivityLog::record(
            event: 'poll_failed',
            message: "Inbox polling failed for mailbox #{$this->mailboxId}: {$exception->getMessage()}",
            level: ActivityLog::LEVEL_WARNING,
            subject: Mailbox::query()->find($this->mailboxId),
        );
    }
}
