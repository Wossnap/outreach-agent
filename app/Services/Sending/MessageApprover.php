<?php

namespace App\Services\Sending;

use App\Models\Enrollment;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * The single place a draft is approved or rejected.
 *
 * The dashboard and the API both call this. Two copies of it is how the API
 * ends up missing a guard the UI has, so neither owns the logic itself.
 */
class MessageApprover
{
    public function __construct(
        protected SendScheduler $scheduler,
        protected EnrollmentStopper $stopper,
    ) {}

    /**
     * Approve a draft and give it a send slot.
     *
     * A null slot with approved=true is not an error: it means no mailbox was
     * sendable at that moment. The message stays approved and unscheduled, and
     * ReconcileStuckMessages picks it up once a mailbox is back.
     *
     * @return array{approved: bool, scheduled_at: ?CarbonImmutable}
     */
    public function approve(Message $message): array
    {
        if ($message->status !== Message::STATUS_PENDING_APPROVAL) {
            return ['approved' => false, 'scheduled_at' => null];
        }

        $message->update(['status' => Message::STATUS_APPROVED, 'approved_at' => now()]);

        return ['approved' => true, 'scheduled_at' => $this->scheduler->schedule($message)];
    }

    /**
     * Reject a draft, which also takes the contact out of the sequence.
     *
     * Leaving the enrollment active would strand it: nothing advances it, so no
     * further email is ever drafted, and the ingest API keeps refusing to
     * re-add the contact because they still count as enrolled.
     */
    public function reject(Message $message, ?string $note = null): bool
    {
        if ($message->status !== Message::STATUS_PENDING_APPROVAL) {
            return false;
        }

        $message->update([
            'status' => Message::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_note' => $note ?: null,
        ]);

        if ($message->enrollment) {
            $this->stopper->stop(
                $message->enrollment,
                Enrollment::STATUS_STOPPED_REJECTED,
                'Draft rejected'.($note ? ': '.$note : ''),
            );
        }

        return true;
    }
}
