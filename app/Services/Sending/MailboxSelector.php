<?php

namespace App\Services\Sending;

use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Picks the mailbox for a new enrollment. The choice is pinned for the whole
 * sequence so every step sends from the same address and threads correctly.
 */
class MailboxSelector
{
    public function select(): ?Mailbox
    {
        $candidates = Mailbox::query()
            ->where('status', Mailbox::STATUS_ACTIVE)
            ->where('health_status', '!=', 'critical')
            ->whereNotNull('google_refresh_token')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $loads = $this->todayLoads($candidates);

        // Least projected load relative to each mailbox's effective (warmup-
        // aware) cap; mailboxes already at cap are skipped entirely.
        $scored = $candidates
            ->map(function (Mailbox $mailbox) use ($loads) {
                $cap = max(1, $mailbox->effectiveDailyCap());
                $load = $loads->get($mailbox->id, 0);

                return $load >= $cap ? null : ['mailbox' => $mailbox, 'ratio' => $load / $cap];
            })
            ->filter();

        if ($scored->isEmpty()) {
            return null;
        }

        $best = $scored->min(fn (array $s) => $s['ratio']);

        return $scored
            ->filter(fn (array $s) => abs($s['ratio'] - $best) < 0.0001)
            ->map(fn (array $s) => $s['mailbox'])
            ->random();
    }

    /**
     * Messages per mailbox occupying today's quota: already sent today plus
     * anything scheduled/being sent today.
     */
    protected function todayLoads(Collection $mailboxes): Collection
    {
        return Message::query()
            ->whereIn('mailbox_id', $mailboxes->pluck('id'))
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('status', Message::STATUS_SENT)
                        ->whereBetween('sent_at', [now()->startOfDay(), now()->endOfDay()]);
                })->orWhere(function ($q) {
                    $q->whereIn('status', [Message::STATUS_SCHEDULED, Message::STATUS_SENDING])
                        ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]);
                });
            })
            ->selectRaw('mailbox_id, count(*) as total')
            ->groupBy('mailbox_id')
            ->pluck('total', 'mailbox_id');
    }
}
