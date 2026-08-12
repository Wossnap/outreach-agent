<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['email', 'name', 'company', 'website', 'custom', 'source'];

    protected function casts(): array
    {
        return ['custom' => 'array'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
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
