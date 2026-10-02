<?php

namespace App\Services\Enrichment;

/**
 * An address a finder returned, and how sure it claims to be.
 */
readonly class FoundEmail
{
    public function __construct(
        public string $email,
        /** 0 to 100 where the provider gives one, null where it does not. */
        public ?int $confidence = null,
        /** Whatever the provider sent back, kept for the lookup record. */
        public array $detail = [],
        /**
         * What it told us about the person, as opposed to about the lookup.
         *
         * Providers return a good deal more than an address: a job title, a
         * company, a city, a LinkedIn profile. It goes on the lead under the
         * provider's name, rather than being read once and discarded.
         *
         * @var array<string, mixed>
         */
        public array $extra = [],
        /**
         * The finder's own check of the address, where it runs one.
         *
         * Hunter checks every address it finds at no extra cost. Valid is
         * taken as it is; anything else decides which verifier is paid to
         * look again. Null when the finder says nothing either way.
         */
        public ?Verdict $verdict = null,
    ) {}
}
