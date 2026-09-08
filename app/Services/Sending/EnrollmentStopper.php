<?php

namespace App\Services\Sending;

use App\Models\Enrollment;
use App\Models\Message;

/**
 * The single place an enrollment stops: sets the terminal status and cancels
 * every message that would still lead to a send.
 */
class EnrollmentStopper
{
    public function stop(Enrollment $enrollment, string $status, string $reason): void
    {
        /*
         * Anything still holding a place is stoppable, which now means waiting
         * as well as active.
         *
         * Waiting counts as open. A waiting enrollment is every bit as live as
         * an active one - it is why the person cannot be enrolled again - so
         * unsubscribing, bouncing, suppressing and the cancel API all have to
         * reach it. Stopping only active ones would leave the row in place for
         * good, with nothing on any screen to say why.
         */
        if (! in_array($enrollment->status, Enrollment::openStatuses(), true)) {
            return;
        }

        $enrollment->update([
            'status' => $status,
            'stopped_at' => now(),
            'stop_reason' => $reason,
        ]);

        $enrollment->messages()
            ->whereIn('status', Message::CANCELLABLE_STATUSES)
            ->update(['status' => Message::STATUS_CANCELLED, 'updated_at' => now()]);
    }
}
