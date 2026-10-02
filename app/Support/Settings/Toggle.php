<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * A setting that is either on or off, and that somebody changes while the
 * system is running.
 *
 * The behaviour is here rather than on each switch so they cannot drift apart
 * over what "nobody has set this yet" means. A switch defaults to on, meaning a
 * missing row is one nobody has turned off, unless it says otherwise in
 * DEFAULT: a mode that changes what is used has to be chosen, not assumed.
 *
 * A subclass supplies the key and, where it is useful, a count of who is
 * affected by the switch as it stands.
 */
abstract class Toggle
{
    /** What the switch is before anybody has touched it. */
    public const DEFAULT = true;

    public static function isOn(): bool
    {
        return (bool) Setting::get(static::KEY, static::DEFAULT);
    }

    public static function isOff(): bool
    {
        return ! static::isOn();
    }

    public static function turnOn(): void
    {
        Setting::put(static::KEY, true);
    }

    public static function turnOff(): void
    {
        Setting::put(static::KEY, false);
    }
}
