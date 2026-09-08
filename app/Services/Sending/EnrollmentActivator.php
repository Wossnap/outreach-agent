<?php

namespace App\Services\Sending;

use App\Jobs\DraftEmailJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts a contact into an automation, and starts them when we may email them.
 *
 * There are three ways in - the push API, the enroll API, and the waterfall
 * confirming an address for somebody who was already waiting - and all three
 * have to make the same decisions: is this person suppressed, are they already
 * in this automation, which mailbox sends to them, and may we send at all yet.
 * Those decisions live here once rather than in three places that drift.
 */
class EnrollmentActivator
{
    public function __construct(protected MailboxSelector $mailboxSelector) {}

    /**
     * Whether this contact already occupies a place in this automation.
     *
     * Waiting counts. Somebody pushed twice while their address is being looked
     * up must not end up enrolled twice and then sent the sequence twice the
     * moment it comes back.
     */
    public function hasOpenEnrollment(Contact $contact, Automation $automation): bool
    {
        return Enrollment::query()
            ->where('contact_id', $contact->id)
            ->where('automation_id', $automation->id)
            ->whereIn('status', Enrollment::openStatuses())
            ->exists();
    }

    /**
     * Enroll a contact, active if we may email them and waiting if we may not.
     *
     * A waiting enrollment gets no mailbox: the choice balances load across
     * inboxes on the day of sending, and one made now could be days stale by
     * the time the address is confirmed.
     */
    public function enroll(Contact $contact, Automation $automation): Enrollment
    {
        /*
         * Somebody who has opted out is never made active, whatever their
         * address says about itself.
         *
         * activateWaiting has always checked this and enroll did not, so the
         * guard lived in each caller instead of in the one place that acts:
         * both happened to remember, and a third would have started a live
         * sequence for somebody who asked us to stop. They enroll as waiting
         * instead, which is inert - the release path checks suppression too,
         * so nothing starts unless the opt-out is lifted.
         */
        $sendable = $contact->isSendable() && ! $contact->isSuppressed();

        $enrollment = DB::transaction(fn (): Enrollment => Enrollment::query()->create([
            'contact_id' => $contact->id,
            'automation_id' => $automation->id,
            'mailbox_id' => $sendable ? $this->mailboxSelector->select()?->id : null,
            'status' => $sendable ? Enrollment::STATUS_ACTIVE : Enrollment::STATUS_WAITING_EMAIL,
            'current_step' => 0,
        ]));

        if ($sendable) {
            DraftEmailJob::dispatch($enrollment->id, 1);
        }

        return $enrollment;
    }

    /**
     * Start everything that is waiting and no longer has a reason to wait.
     *
     * For when the rule changes rather than the address does: switching off
     * "require a confirmed address" makes everybody holding an unconfirmed one
     * sendable, and their enrollments would otherwise sit waiting until
     * something happened to push them again.
     *
     * Somebody with no address at all still waits, because there is still
     * nowhere to send to. That is decided by isSendable, which is asked per
     * contact rather than assumed here.
     *
     * @return int how many enrollments were started
     */
    public function releaseEveryoneNowSendable(): int
    {
        return $this->waitingOnConfirmationOnly()
            ->sum(fn (Contact $contact): int => $this->activateWaiting($contact));
    }

    /**
     * How many enrollments relaxing the rule would start.
     *
     * Asked before the switch is thrown, so the warning on the page can say
     * what is about to happen. It counts enrollments rather than people,
     * because one person can be waiting in more than one sequence.
     */
    public function countWaitingOnConfirmationOnly(): int
    {
        return (int) $this->waitingOnConfirmationOnly()->sum(
            fn (Contact $contact): int => $contact->enrollments
                ->where('status', Enrollment::STATUS_WAITING_EMAIL)
                ->count(),
        );
    }

    /**
     * Everybody whose only reason for waiting is that nothing has confirmed
     * their address.
     *
     * Deliberately does not ask isSendable, which reads the switch: this has to
     * answer the same way whether the rule is currently in force or not, or the
     * warning would say nobody and then start people. Somebody with no address,
     * a dead address, or an opt-out is excluded, because none of those is
     * waiting on the rule.
     *
     * @return Collection<int, Contact>
     */
    private function waitingOnConfirmationOnly()
    {
        return Contact::query()
            ->reachable()
            ->whereNotIn('email', Suppression::query()->select('email'))
            ->whereHas('enrollments', fn ($query) => $query->where('status', Enrollment::STATUS_WAITING_EMAIL))
            ->with('enrollments')
            ->get();
    }

    /**
     * Start everything that was waiting on this contact's address.
     *
     * Called when the waterfall confirms one. Each enrollment picks its mailbox
     * now, at the moment it is actually going to send, and drafts step one
     * exactly as it would have done had the address been known all along.
     *
     * @return int how many were started
     */
    public function activateWaiting(Contact $contact): int
    {
        if (! $contact->isSendable() || $contact->isSuppressed()) {
            return 0;
        }

        $waiting = Enrollment::query()
            ->where('contact_id', $contact->id)
            ->where('status', Enrollment::STATUS_WAITING_EMAIL)
            ->get();

        foreach ($waiting as $enrollment) {
            $enrollment->update([
                'status' => Enrollment::STATUS_ACTIVE,
                'mailbox_id' => $enrollment->mailbox_id ?? $this->mailboxSelector->select()?->id,
            ]);

            DraftEmailJob::dispatch($enrollment->id, 1);
        }

        return $waiting->count();
    }
}
