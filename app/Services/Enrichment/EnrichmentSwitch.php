<?php

namespace App\Services\Enrichment;

use App\Models\Contact;
use App\Support\Settings\Toggle;

/**
 * One switch that stops the waterfall spending money.
 *
 * Every lead submitted without a settled address goes through the waterfall
 * automatically, and the API is open to anything holding a token, so a caller
 * submitting ten thousand leads spends real money at real providers.
 *
 * Switching the providers off one by one is not a substitute for this, and is
 * worse than doing nothing: a lead that arrives WITH an address would run the
 * verify half, find nobody configured, and settle as risky. Risky counts as
 * settled, so it would never be looked at again.
 *
 * Off leaves leads pending instead, the one status meaning "we have not looked
 * yet". They are picked up with "Check the address again" on the leads list
 * once this is switched back on.
 */
class EnrichmentSwitch extends Toggle
{
    public const KEY = 'enrichment.enabled';

    /**
     * How many leads are waiting for this to be switched back on.
     *
     * Shown when somebody turns it on, because nothing goes and fetches them:
     * they sit pending until somebody asks for them to be checked again. The
     * number is the difference between knowing that and wondering why the
     * queue is empty.
     */
    public static function waiting(): int
    {
        return Contact::query()->where('email_status', Contact::EMAIL_PENDING)->count();
    }
}
