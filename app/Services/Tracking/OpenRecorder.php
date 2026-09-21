<?php

namespace App\Services\Tracking;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Records that the open pixel for a message was fetched.
 *
 * Only a sent message counts, and only once it has been out for longer than
 * the ignore window: the hits that arrive within seconds of sending are link
 * scanners and prefetchers, not the person the email was for. Nothing here
 * says anything about who fetched it or from where; the counts are directional
 * and are described as such wherever they are shown.
 */
class OpenRecorder
{
    /** @return bool whether an open was recorded */
    public function record(string $token): bool
    {
        $cutoff = now()->subSeconds((int) config('outreach.open_tracking.ignore_seconds_after_send'));

        $base = Message::query()
            ->where('open_token', $token)
            ->where('status', Message::STATUS_SENT)
            ->where('sent_at', '<=', $cutoff);

        // Two statements rather than COALESCE in SQL, so both timestamps come
        // from PHP's clock in UTC and never from the database session's.
        $recorded = (clone $base)->update([
            'last_opened_at' => now(),
            'open_count' => DB::raw('open_count + 1'),
        ]);

        if ($recorded === 0) {
            return false;
        }

        (clone $base)->whereNull('first_opened_at')->update(['first_opened_at' => now()]);

        return true;
    }
}
