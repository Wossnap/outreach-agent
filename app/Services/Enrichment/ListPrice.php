<?php

namespace App\Services\Enrichment;

/**
 * A provider's published rate, as checked on a given day.
 *
 * Not the price you pay. That depends on the plan, the volume tier and anything
 * negotiated, which is why the figure on a provider row stays editable. This is
 * the starting point, so nobody has to go and find it, and nobody types a
 * plausible-looking number they half remember.
 */
readonly class ListPrice
{
    public function __construct(
        public float $perLookup,
        public bool $billedOnMiss,
        /** Where the figure comes from and when it was checked. */
        public string $note,
    ) {}
}
