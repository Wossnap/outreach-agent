<?php

namespace App\Services\Enrichment;

/**
 * What a verifier concluded about an address.
 *
 * CATCH_ALL is separate from VALID on purpose. On a catch-all domain the mail
 * server accepts every address it is offered, so a positive answer says nothing
 * about whether this particular mailbox exists. Roughly a fifth to a third of
 * business domains are configured this way, so treating them as valid would
 * quietly reintroduce exactly the bounces this system exists to prevent.
 *
 * UNKNOWN means the provider could not tell us, which is different again: it is
 * a reason to ask someone else, not a verdict.
 */
enum Verdict: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case CATCH_ALL = 'catch_all';
    case UNKNOWN = 'unknown';

    /**
     * Whether this answer ends the search.
     *
     * Only a yes or a no does. CATCH_ALL joins UNKNOWN in falling through,
     * because it is not a verdict about the address at all: it says the domain
     * accepts everything offered to it, which is a statement about the domain.
     *
     * That distinction is what makes a specialist worth paying for. General
     * verifiers stop at catch-all; one that can resolve them needs to be asked,
     * and it never would be if the first shrug settled the matter. If nobody
     * resolves it the lead still ends up risky, so falling through costs a
     * lookup and can only improve the answer.
     */
    public function isConclusive(): bool
    {
        return $this === self::VALID || $this === self::INVALID;
    }
}
