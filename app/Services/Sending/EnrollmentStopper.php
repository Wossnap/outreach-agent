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
        if (! $enrollment->isActive()) {
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
