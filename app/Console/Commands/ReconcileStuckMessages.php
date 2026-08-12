<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Heals rows orphaned by a worker crash mid-job. A "sending" row that already
 * has a gmail_message_id reached Gmail — flip it to sent. One without never
 * left — mark it failed for a manual retry (auto-requeueing a send risks a
 * duplicate email to a real person).
 */
class ReconcileStuckMessages extends Command
{
    protected $signature = 'outreach:reconcile-stuck {--minutes=15}';

    protected $description = 'Heal or fail messages stuck in sending/drafting after a worker crash';

    public function handle(): int
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

        $this->info('Reconciled '.$stuckSending->count().' sending + '.$stuckDrafting->count().' drafting rows.');

        return self::SUCCESS;
    }
}
