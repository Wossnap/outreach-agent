<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLookup extends Model
{
    /** A finder returned an address, or a verifier reached a verdict. */
    public const RESULT_FOUND = 'found';

    public const RESULT_NOTHING = 'nothing';

    public const RESULT_VALID = 'valid';

    public const RESULT_INVALID = 'invalid';

    public const RESULT_CATCH_ALL = 'catch_all';

    public const RESULT_UNKNOWN = 'unknown';

    /** The provider itself failed. Not the same as it having no answer. */
    public const RESULT_ERROR = 'error';

    /**
     * The free syntax and DNS check, which runs before anything is paid for.
     *
     * Recorded like a lookup so the reason an address was rejected is always on
     * the lead's record, but it is not a provider: nobody sells it and nobody
     * bills for it.
     */
    public const DRIVER_FREE_GATE = 'free-gate';

    /**
     * A message that did not deliver.
     *
     * The only true measure of whether a "valid" verdict was right: the
     * provider that supplied the address hears what became of it. Also not a
     * provider itself.
     */
    public const DRIVER_BOUNCE = 'bounce';

    /**
     * Rows that are recorded here but were never bought from anybody.
     *
     * Kept in the table because they belong on the lead's history, and kept out
     * of the spend report because a table of what each provider costs should
     * only list things somebody charges for. Left in, "Delivery" appeared as a
     * provider with a perfect answer rate and a cost of nothing, which is not a
     * flattering account of a bounce.
     *
     * @return array<int, string>
     */
    public static function pseudoDrivers(): array
    {
        return [self::DRIVER_FREE_GATE, self::DRIVER_BOUNCE];
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * What a verdict means, in words, standing on its own.
     *
     * None of them begin with "said". These head a column already labelled
     * Said, and fill a filter already labelled Said, where "Said: said it is
     * good" reads as a stutter. verdictPhrase() puts the verb back when there
     * is a provider's name in front of it.
     *
     * @return array<string, string>
     */
    public static function results(): array
    {
        return [
            self::RESULT_FOUND => 'Found an address',
            self::RESULT_NOTHING => 'Had nothing',
            self::RESULT_VALID => 'It is good',
            self::RESULT_INVALID => 'It is dead',
            self::RESULT_CATCH_ALL => 'The domain takes anything',
            self::RESULT_UNKNOWN => 'Would not commit',
            self::RESULT_ERROR => 'Failed',
        ];
    }

    public function resultLabel(): string
    {
        return self::results()[$this->result] ?? str_replace('_', ' ', $this->result);
    }

    /**
     * The whole verdict as one sentence, provider first.
     *
     * Written out per result rather than glued together from a name, the word
     * "said" and a label. Only some of these are things a provider said: a
     * finder that returned an address did not say anything, it found
     * something, and "Findymail said found an address" is not English. Nor is
     * "BounceBan said said it is good", which is what gluing produced.
     */
    public function sentence(): string
    {
        return $this->provider_name.$this->verdictPhrase();
    }

    /** The same sentence without the name, so a view can style the name itself. */
    public function verdictPhrase(): string
    {
        return ' '.match ($this->result) {
            self::RESULT_FOUND => 'found an address',
            self::RESULT_NOTHING => 'had nothing',
            self::RESULT_VALID => 'said it is good',
            self::RESULT_INVALID => 'said it is dead',
            self::RESULT_CATCH_ALL => 'said the domain takes anything',
            self::RESULT_UNKNOWN => 'would not commit either way',
            self::RESULT_ERROR => 'failed',
            default => 'said '.str_replace('_', ' ', $this->result),
        };
    }

    /**
     * What the provider said, in one line a person can read.
     *
     * The stored detail is the provider's own vocabulary, and it was being
     * printed as raw JSON on the lead's history, where nobody could read it. It
     * is still kept exactly as it arrived, because the point of it is to settle
     * an argument about our translation of it; this only presents it.
     *
     * Null when the provider said nothing, which some do on a miss.
     */
    public function explain(): ?string
    {
        $detail = $this->detail;

        if (! is_array($detail) || $detail === []) {
            return null;
        }

        // A provider that gave a whole sentence has already done this job
        // better than any joining of fields would.
        foreach (['reason', 'error', 'message'] as $key) {
            if (filled($detail[$key] ?? null)) {
                return (string) $detail[$key];
            }
        }

        return collect($this->detailPairs())
            ->map(fn (string $value, string $label): string => mb_strtolower($label).' '.$value)
            ->implode(', ');
    }

    /**
     * The provider's answer, field by field, for reading in full.
     *
     * @return array<string, string> label => value, both ready to print
     */
    public function detailPairs(): array
    {
        $detail = $this->detail;

        if (! is_array($detail)) {
            return [];
        }

        $pairs = [];

        foreach ($detail as $key => $value) {
            $pairs[ucfirst(str_replace('_', ' ', (string) $key))] = match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                is_array($value) => $value === [] ? 'none' : implode(', ', array_map(strval(...), $value)),
                $value === null => 'not said',
                default => (string) $value,
            };
        }

        return $pairs;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(EnrichmentProvider::class, 'enrichment_provider_id');
    }
}
