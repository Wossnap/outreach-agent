<?php

namespace App\Services\Sending;

use App\Models\Mailbox;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Assigns an approved message its send slot. Slots are second-granular and
 * randomized so sends never align to cron minute boundaries: each slot lands
 * a random gap after the mailbox's last occupied slot, clamped into the
 * mailbox's send window and daily (warmup-aware) cap.
 */
class SendScheduler
{
    public function __construct(
        protected MailboxSelector $mailboxSelector,
    ) {}

    /**
     * Schedule the message. Returns the assigned UTC slot, or null when no
     * sendable mailbox exists (message stays approved and unscheduled).
     */
    public function schedule(Message $message): ?CarbonImmutable
    {
        $mailbox = $this->resolveMailbox($message);

        if (! $mailbox) {
            return null;
        }

        $slot = $this->nextSlot($mailbox);

        $message->update([
            'status' => Message::STATUS_SCHEDULED,
            'mailbox_id' => $mailbox->id,
            'scheduled_at' => $slot,
        ]);

        return $slot;
    }

    protected function resolveMailbox(Message $message): ?Mailbox
    {
        $mailbox = $message->mailbox ?? $message->enrollment->mailbox;

        if ($mailbox && $mailbox->isSendable()) {
            return $mailbox;
        }

        // Enrollment was created while no mailbox was available (or its
        // mailbox has since been paused/disconnected) — try again now and pin
        // the pick to the enrollment for the rest of the sequence.
        $mailbox = $this->mailboxSelector->select();

        if ($mailbox) {
            $message->enrollment->update(['mailbox_id' => $mailbox->id]);
        }

        return $mailbox;
    }

    protected function nextSlot(Mailbox $mailbox): CarbonImmutable
    {
        $lastOccupied = $this->lastOccupiedSlot($mailbox);

        $gapSeconds = random_int(
            max(60, $mailbox->min_gap_minutes * 60),
            max(120, $mailbox->max_gap_minutes * 60),
        );

        $candidate = $lastOccupied->addSeconds($gapSeconds);

        // Clamp into the send window / cap, rolling forward day by day.
        for ($i = 0; $i < 60; $i++) {
            $candidate = $this->clampIntoWindow($candidate, $mailbox);

            if ($this->dayHasCapacity($mailbox, $candidate)) {
                return $candidate;
            }

            $candidate = $this->startOfNextSendingDay($candidate, $mailbox);
        }

        return $candidate;
    }

    protected function lastOccupiedSlot(Mailbox $mailbox): CarbonImmutable
    {
        $lastScheduled = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->whereIn('status', [Message::STATUS_SCHEDULED, Message::STATUS_SENDING])
            ->max('scheduled_at');

        $lastSent = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('status', Message::STATUS_SENT)
            ->max('sent_at');

        $last = collect([$lastScheduled, $lastSent])
            ->filter()
            ->map(fn ($ts) => CarbonImmutable::parse($ts, 'UTC'))
            ->max();

        $now = CarbonImmutable::now('UTC');

        return $last === null || $last->lessThan($now) ? $now : $last;
    }

    protected function clampIntoWindow(CarbonImmutable $candidate, Mailbox $mailbox): CarbonImmutable
    {
        $local = $candidate->setTimezone($mailbox->send_timezone);

        if (! $this->isSendingDay($local, $mailbox)) {
            return $this->startOfNextSendingDay($candidate, $mailbox);
        }

        [$startHour, $startMinute] = $this->parseTime($mailbox->send_window_start);
        [$endHour, $endMinute] = $this->parseTime($mailbox->send_window_end);

        $windowStart = $local->setTime($startHour, $startMinute);
        $windowEnd = $local->setTime($endHour, $endMinute);

        if ($local->lessThan($windowStart)) {
            // Jitter into the first stretch of the window rather than firing
            // at exactly window-open every day.
            return $windowStart->addSeconds(random_int(0, 45 * 60))->setTimezone('UTC');
        }

        if ($local->greaterThan($windowEnd)) {
            return $this->startOfNextSendingDay($candidate, $mailbox);
        }

        return $candidate;
    }

    protected function startOfNextSendingDay(CarbonImmutable $candidate, Mailbox $mailbox): CarbonImmutable
    {
        $local = $candidate->setTimezone($mailbox->send_timezone)->addDay()->startOfDay();

        while (! $this->isSendingDay($local, $mailbox)) {
            $local = $local->addDay();
        }

        [$startHour, $startMinute] = $this->parseTime($mailbox->send_window_start);

        return $local->setTime($startHour, $startMinute)
            ->addSeconds(random_int(0, 45 * 60))
            ->setTimezone('UTC');
    }

    protected function isSendingDay(CarbonImmutable $local, Mailbox $mailbox): bool
    {
        return $mailbox->send_weekends || $local->isWeekday();
    }

    protected function dayHasCapacity(Mailbox $mailbox, CarbonImmutable $slotUtc): bool
    {
        $local = $slotUtc->setTimezone($mailbox->send_timezone);
        $dayStartUtc = $local->startOfDay()->setTimezone('UTC');
        $dayEndUtc = $local->endOfDay()->setTimezone('UTC');

        $occupied = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                $query->where(function ($q) use ($dayStartUtc, $dayEndUtc) {
                    $q->whereIn('status', [Message::STATUS_SCHEDULED, Message::STATUS_SENDING])
                        ->whereBetween('scheduled_at', [$dayStartUtc, $dayEndUtc]);
                })->orWhere(function ($q) use ($dayStartUtc, $dayEndUtc) {
                    $q->where('status', Message::STATUS_SENT)
                        ->whereBetween('sent_at', [$dayStartUtc, $dayEndUtc]);
                });
            })
            ->count();

        return $occupied < $mailbox->effectiveDailyCap();
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function parseTime(string $time): array
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return [(int) $hour, (int) $minute];
    }
}
