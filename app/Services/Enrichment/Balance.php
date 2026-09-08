<?php

namespace App\Services\Enrichment;

/**
 * What a provider has left to spend.
 *
 * Three of the six report more than one pool, and the larger number is not
 * always the one our calls draw down. Hunter shows 48 searches and 96
 * verifications; we only ever search. Reoon shows 100 instant credits and 14
 * daily ones; we run it in power mode, which spends the instant ones. Findymail
 * shows 24 finder credits and 10 verifier credits; we only ever find.
 *
 * So a single figure would be wrong for half of them, and wrong in the
 * direction that matters: it would report credit we cannot actually spend. The
 * pool we spend is named, and anything else the provider reports is carried
 * alongside it and marked as untouchable rather than added in or dropped.
 */
readonly class Balance
{
    public function __construct(
        /** What is left of the pool this provider's calls actually draw down. */
        public int $remaining,
        /** What that pool is called, in the provider's own words. */
        public string $pool,
        /**
         * Other pools the provider reports that our calls never touch.
         *
         * @var array<string, int> what it is called => what is left of it
         */
        public array $untouched = [],
    ) {}

    /** The pool we spend, and a warning about the ones we cannot. */
    public function describe(): string
    {
        $parts = [$this->pool];

        foreach ($this->untouched as $label => $figure) {
            $parts[] = number_format($figure).' '.$label.' we never spend';
        }

        return implode(', plus ', $parts);
    }

    public function isEmpty(): bool
    {
        return $this->remaining <= 0;
    }
}
