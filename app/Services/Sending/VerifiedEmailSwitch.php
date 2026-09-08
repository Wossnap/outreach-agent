<?php

namespace App\Services\Sending;

use App\Models\Contact;
use App\Support\Settings\Toggle;

/**
 * Whether an address has to be confirmed before anything is sent to it.
 *
 * On by default, because bounces cost us delivery to everybody else: an
 * address nobody has checked is a guess, however confident the caller was.
 * On, a contact waits in `waiting_email` until the waterfall confirms the
 * address, and is enrolled properly the moment it does.
 *
 * It is a setting rather than a constant so a system with no verifier
 * configured yet is not left unable to send at all. Off, a supplied address is
 * trusted and drafting starts at once.
 */
class VerifiedEmailSwitch extends Toggle
{
    public const KEY = 'sending.require_verified_email';

    /**
     * How many contacts are held back only by this switch.
     *
     * Shown beside it, because these are the people who become sendable the
     * moment somebody turns it off.
     */
    public static function waiting(): int
    {
        return Contact::query()
            ->whereNotNull('email')
            ->where('email_status', '!=', Contact::EMAIL_VALID)
            ->count();
    }
}
