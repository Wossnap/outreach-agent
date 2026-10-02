<?php

namespace App\Services\Enrichment;

use App\Jobs\EnrichContact;
use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * Send leads back through the waterfall from the start.
 *
 * A settled lead is never revisited on its own: an answer is only paid for
 * once. This is how one gets another go after a provider is added, given a key,
 * or reordered.
 *
 * One place rather than two, because the rules about who may be rechecked are
 * the interesting part and there are now two ways in: a button on a single lead
 * and a button on a selection. Written twice, they would have drifted the first
 * time one of the rules changed, and the expensive direction of that drift is
 * paying six providers for somebody who has opted out.
 */
class Recheck
{
    /**
     * @param  Collection<int, Contact>  $contacts
     */
    public function these(Collection $contacts): RecheckOutcome
    {
        if (EnrichmentSwitch::isOff()) {
            return new RecheckOutcome(queued: 0, skippedSuppressed: 0, enrichmentOff: true);
        }

        $queued = 0;
        $suppressed = 0;

        foreach ($contacts as $contact) {
            /*
             * Paying to find an address for somebody who has opted out. Every
             * provider this reaches charges us, and the answer could not be
             * used even if it came back.
             */
            if ($contact->isSuppressed()) {
                $suppressed++;

                continue;
            }

            $contact->update(['email_status' => Contact::EMAIL_PENDING, 'email_checked_at' => null]);

            // Somebody asked for a fresh answer, so providers that already
            // gave one are asked again rather than skipped.
            EnrichContact::dispatch($contact->id, askAgain: true);
            $queued++;
        }

        return new RecheckOutcome(queued: $queued, skippedSuppressed: $suppressed, enrichmentOff: false);
    }
}
