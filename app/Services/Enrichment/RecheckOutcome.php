<?php

namespace App\Services\Enrichment;

/**
 * What happened when leads were sent back through the waterfall.
 *
 * Three separate facts rather than one number, because the screen has to say
 * which of them applies: nothing queued because enrichment is switched off is a
 * different message from nothing queued because everybody selected had opted
 * out, and both are different from nothing selected at all.
 */
readonly class RecheckOutcome
{
    public function __construct(
        public int $queued,
        public int $skippedSuppressed,
        public bool $enrichmentOff,
    ) {}

    /** One sentence naming everything that happened, for the screen. */
    public function describe(): string
    {
        if ($this->enrichmentOff) {
            return 'Enrichment is switched off, so nothing would be looked up. Turn it on above.';
        }

        if ($this->queued === 0 && $this->skippedSuppressed === 0) {
            return 'Nothing was selected.';
        }

        $parts = [];

        if ($this->queued > 0) {
            $parts[] = $this->queued.' lead(s) queued. The status moves as each provider answers.';
        }

        if ($this->skippedSuppressed > 0) {
            // Said out loud rather than quietly done, because somebody who
            // selected fifty leads and got forty-eight back has a right to know
            // which two were left out and why.
            $parts[] = $this->skippedSuppressed.' skipped: they have opted out, so looking up their '
                .'address would be spending money on somebody we may never email.';
        }

        return implode(' ', $parts);
    }
}
