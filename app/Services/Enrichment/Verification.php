<?php

namespace App\Services\Enrichment;

/**
 * What a verifier concluded, and what it said to get there.
 *
 * The verdict alone is not enough to argue with. When a provider calls an
 * address risky and somebody believes it is fine, the only way to settle it is
 * to read what the provider actually reported: the score it gave, whether it
 * thought the domain accepts everything, what its own wording was.
 *
 * Kept per provider and in their own vocabulary rather than flattened into
 * ours, because the disagreement is usually in the detail we would have thrown
 * away.
 */
readonly class Verification
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public Verdict $verdict,
        public array $detail = [],
    ) {}
}
