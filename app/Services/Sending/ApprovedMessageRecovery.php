<?php

namespace App\Services\Sending;

use App\Models\Message;

/**
 * Gives a send slot to emails approved while no mailbox could send.
 *
 * Nothing else in the system reads the approved status, so without this an
 * email approved during a pause is never sent, never queued and never reported
 * as failed. Runs on a schedule, and again the moment a mailbox is resumed.
 */
class ApprovedMessageRecovery
{
    public function __construct(
        protected SendScheduler $scheduler,
    ) {}

    /**
     * @return array<int> ids of the messages given a send slot
     */
    public function run(): array
    {
        $waiting = Message::query()
            ->where('status', Message::STATUS_APPROVED)
            ->whereNull('sent_at')
            ->with('enrollment')
            ->get();

        $scheduled = [];

        foreach ($waiting as $message) {
            // The sequence stopped while this sat waiting — a reply, an opt-out
            // or a rejection. Sending now would contradict that.
            if (! $message->enrollment?->isActive()) {
                $message->update(['status' => Message::STATUS_CANCELLED]);

                continue;
            }

            if ($this->scheduler->schedule($message) !== null) {
                $scheduled[] = $message->id;
            }
        }

        return $scheduled;
    }
}
