<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Enrichment\EmailWaterfall;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the waterfall for one contact, off the request that submitted them.
 *
 * Unique per contact so somebody submitted twice in quick succession is not
 * looked up twice, which would be paid for twice.
 */
class EnrichContact implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Room for the whole chain, including the slow specialist at the end.
     *
     * Most providers answer in a second or two, but a catch-all verifier can
     * legitimately hold the line for eighty. A job killed part way leaves the
     * contact stranded mid-lookup after the money has already been spent.
     */
    public int $timeout = 300;

    /**
     * Attempted once.
     *
     * A retry would run the waterfall again from the top and pay for every
     * provider a second time. Providers that fail are already handled inside
     * the waterfall, which falls through to the next one and switches off
     * anything that keeps failing.
     */
    public int $tries = 1;

    /**
     * How long the "one lookup at a time" claim survives without being cleared.
     *
     * Released normally when the job finishes, so this only matters when it
     * never does: a worker killed mid-lookup, or a container restarted during a
     * deploy, leaves the claim behind with nobody to clear it. Without an
     * expiry that contact can never be looked up again, and "check the address
     * again" does nothing at all, which reads as a broken button rather than a
     * stale lock.
     *
     * Comfortably longer than the timeout above, so a lookup that legitimately
     * runs to its full length is never overtaken by a second one.
     */
    public int $uniqueFor = 900;

    /**
     * @param  bool  $askAgain  ask providers that already answered too. Only
     *                          "check the address again" sets it.
     */
    public function __construct(public int $contactId, public bool $askAgain = false) {}

    public function uniqueId(): string
    {
        return (string) $this->contactId;
    }

    public function handle(EmailWaterfall $waterfall): void
    {
        $contact = Contact::find($this->contactId);

        if ($contact) {
            $waterfall->run($contact, $this->askAgain);
        }
    }
}
