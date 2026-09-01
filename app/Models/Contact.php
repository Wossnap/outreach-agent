<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'email', 'name', 'first_name', 'last_name', 'company', 'website', 'custom', 'source',
    ];

    protected function casts(): array
    {
        return ['custom' => 'array'];
    }

    /**
     * Reconcile `name` against `first_name`/`last_name`.
     *
     * Whatever the caller supplied wins. Anything not supplied is derived from
     * what was, but only when the stored value is blank, so re-sending one
     * part never silently rewrites a name that is already on record.
     *
     * `name` is kept rather than derived because not every contact splits:
     * mononyms and role addresses ("Support Team") would be mangled by it.
     *
     * @param  array<string, mixed>  $payload  what the caller sent
     * @param  array<string, mixed>  $existing  what is already stored, if anything
     * @return array<string, string>
     */
    public static function resolveNameFields(array $payload, array $existing = []): array
    {
        $given = static fn (array $source, string $key): ?string => filled($source[$key] ?? null)
            ? trim((string) $source[$key])
            : null;

        $name = $given($payload, 'name');
        $first = $given($payload, 'first_name');
        $last = $given($payload, 'last_name');

        $resolved = array_filter(
            ['name' => $name, 'first_name' => $first, 'last_name' => $last],
            fn (?string $value): bool => filled($value),
        );

        if ($name === null && ($first !== null || $last !== null) && $given($existing, 'name') === null) {
            $resolved['name'] = trim($first.' '.$last);
        }

        if ($name !== null && $first === null && $last === null
            && $given($existing, 'first_name') === null && $given($existing, 'last_name') === null) {
            // First space only: the leading token is the given name, and
            // everything after it belongs to the surname ("van der Berg").
            $resolved['first_name'] = Str::before($name, ' ');

            if (Str::contains($name, ' ')) {
                $resolved['last_name'] = trim(Str::after($name, ' '));
            }
        }

        return $resolved;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class);
    }

    public function suppression(): ?Suppression
    {
        return Suppression::query()->where('email', $this->email)->first();
    }

    public function isSuppressed(): bool
    {
        return Suppression::query()->where('email', $this->email)->exists();
    }
}
