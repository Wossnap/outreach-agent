<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Message;
use App\Services\Sending\ApprovedMessageRecovery;
use Illuminate\Console\Command;

/**
 * Heals rows orphaned by a worker crash mid-job. A "sending" row that already
 * has a gmail_message_id reached Gmail — flip it to sent. One without never
 * left — mark it failed for a manual retry (auto-requeueing a send risks a
 * duplicate email to a real person).
 *
 * Also picks up approved messages that never got a send slot, which happens
 * when every mailbox was paused or disconnected at the moment of approval.
 * Nothing else in the system reads the approved status, so without this they
 * would sit unsent indefinitely.
 */
class ReconcileStuckMessages extends Command
{
    protected $signature = 'outreach:reconcile-stuck {--minutes=15}';

    protected $description = 'Heal or fail messages stuck in sending/drafting after a worker crash';

    public function handle(ApprovedMessageRecovery $recovery): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $stuckSending = Message::query()
            ->where('status', Message::STATUS_SENDING)
            ->where('sending_started_at', '<', $threshold)
            ->get();

        foreach ($stuckSending as $message) {
            if ($message->gmail_message_id) {
                $message->update(['status' => Message::STATUS_SENT, 'sent_at' => $message->sent_at ?? now()]);
                $this->line("Healed message #{$message->id} to sent (it reached Gmail).");

                continue;
            }

            $message->update(['status' => Message::STATUS_FAILED, 'error' => 'Stuck in sending — worker likely crashed before the Gmail call completed.']);

            ActivityLog::record(
                event: 'send_stuck',
                message: "Message #{$message->id} was stuck in sending and marked failed. Retry it from the Activity page.",
                level: ActivityLog::LEVEL_ERROR,
                subject: $message,
                retryable: true,
            );
        }

        $stuckDrafting = Message::query()
            ->where('status', Message::STATUS_DRAFTING)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get();

        foreach ($stuckDrafting as $message) {
            $message->update(['status' => Message::STATUS_DRAFT_FAILED, 'error' => 'Stuck in drafting for over 30 minutes.']);

            ActivityLog::record(
                event: 'draft_stuck',
                message: "Message #{$message->id} was stuck in drafting and marked draft_failed.",
                level: ActivityLog::LEVEL_ERROR,
                subject: $message,
                retryable: true,
            );
        }

        $rescheduled = count($recovery->run());

        $this->info('Reconciled '.$stuckSending->count().' sending + '.$stuckDrafting->count().' drafting rows, rescheduled '.$rescheduled.' approved.');

        return self::SUCCESS;
    }
}
