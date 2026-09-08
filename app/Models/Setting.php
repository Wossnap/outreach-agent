<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting.
 *
 * Not cached. A read is a single indexed primary-key lookup, and a cached kill
 * switch is one that can be minutes late in taking effect.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::query()->find($key);

        return $setting ? $setting->value : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
