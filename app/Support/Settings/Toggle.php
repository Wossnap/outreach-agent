<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * A setting that is either on or off, and that somebody changes while the
 * system is running.
 *
 * The behaviour is here rather than on each switch so they cannot drift apart
 * over what "nobody has set this yet" means. Every switch defaults to on: a
 * missing row means nobody has turned it off.
 *
 * A subclass supplies the key and, where it is useful, a count of who is
 * affected by the switch as it stands.
 */
abstract class Toggle
{
    /** On unless somebody has deliberately turned it off. */
    public static function isOn(): bool
    {
        return (bool) Setting::get(static::KEY, true);
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
