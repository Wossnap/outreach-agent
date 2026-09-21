<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\Suppression;
use App\Services\Gmail\GmailSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    /**
     * Never blind-retry a send: a timeout after Gmail accepted the message
     * would mean a duplicate email to a real person. The reconciler heals
     * or fails stuck rows, and failures get a manual retry button.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $messageId,
    ) {}

    public function handle(GmailSender $sender): void
    {
        $message = Message::query()
            ->with(['enrollment', 'contact', 'mailbox', 'sequenceStep'])
            ->find($this->messageId);

        if (! $message || $message->status !== Message::STATUS_SENDING) {
            return;
        }

        // Last-second guards: a reply or suppression recorded after
        // scheduling wins over the send.
        if (! $message->enrollment->isActive() || Suppression::isSuppressed($message->contact->email)) {
            $message->update(['status' => Message::STATUS_CANCELLED]);

            return;
        }

        $message->increment('attempts');

        // The pixel URL is built into the MIME, so the token has to be on the
        // row before the send, not after: a hit that arrives while the
        // post-send update is still running must still find its message.
        if (config('outreach.open_tracking.enabled') && ! $message->open_token) {
            $message->update(['open_token' => Message::generateOpenToken()]);
        }

        $result = $sender->send($message);

        $message->update([
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'gmail_message_id' => $result['gmail_message_id'],
            'gmail_thread_id' => $result['gmail_thread_id'],
            'rfc_message_id' => $result['rfc_message_id'],
            'error' => null,
        ]);

        $this->advanceEnrollment($message);
    }

    protected function advanceEnrollment(Message $message): void
    {
        $enrollment = $message->enrollment;
        $position = $message->sequenceStep->position;

        $enrollment->update([
            'current_step' => max($enrollment->current_step, $position),
            'gmail_thread_id' => $enrollment->gmail_thread_id ?: $message->gmail_thread_id,
        ]);

        $hasNextStep = $enrollment->automation
            ->activeSteps()
            ->where('position', '>', $position)
            ->exists();

        if (! $hasNextStep) {
            $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message && $message->status === Message::STATUS_SENDING) {
            $message->update(['status' => Message::STATUS_FAILED, 'error' => $exception->getMessage()]);
        }

        $message?->mailbox?->update(['last_send_error' => $exception->getMessage()]);

        ActivityLog::record(
            event: 'send_failed',
            message: "Send failed for message #{$this->messageId}: {$exception->getMessage()}",
            level: ActivityLog::LEVEL_ERROR,
            subject: $message,
            context: ['message_id' => $this->messageId],
            retryable: true,
        );
    }
}
