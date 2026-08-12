<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Mailbox extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'domain_id', 'email', 'display_name',
        'google_access_token', 'google_refresh_token', 'google_token_expires_at', 'google_scopes',
        'gmail_history_id', 'status', 'paused_reason',
        'daily_cap', 'min_gap_minutes', 'max_gap_minutes',
        'send_window_start', 'send_window_end', 'send_timezone', 'send_weekends',
        'warmup_enabled', 'warmup_started_at', 'warmup_start_per_day', 'warmup_increment_per_day',
        'bounce_rate_7d', 'reply_rate_7d', 'sent_7d', 'health_status', 'last_polled_at', 'last_send_error',
    ];

    protected function casts(): array
    {
        return [
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
            'google_scopes' => 'array',
            'send_weekends' => 'boolean',
            'warmup_enabled' => 'boolean',
            'warmup_started_at' => 'datetime',
            'bounce_rate_7d' => 'float',
            'reply_rate_7d' => 'float',
            'last_polled_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function healthChecks(): MorphMany
    {
        return $this->morphMany(HealthCheck::class, 'checkable');
    }

    /**
     * Daily cap with the warmup ramp applied: starts at warmup_start_per_day
     * and grows by warmup_increment_per_day each day since warmup started,
     * capped at daily_cap.
     */
    public function effectiveDailyCap(): int
    {
        if (! $this->warmup_enabled || $this->warmup_started_at === null) {
            return (int) $this->daily_cap;
        }

        $days = (int) $this->warmup_started_at->startOfDay()->diffInDays(now()->startOfDay());
        $ramped = $this->warmup_start_per_day + ($this->warmup_increment_per_day * $days);

        return (int) min($this->daily_cap, max(0, $ramped));
    }

    public function isWarming(): bool
    {
        return $this->warmup_enabled
            && $this->warmup_started_at !== null
            && $this->effectiveDailyCap() < (int) $this->daily_cap;
    }

    public function isSendable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->health_status !== Domain::HEALTH_CRITICAL;
    }

    public function pause(string $reason): void
    {
        $this->update(['status' => self::STATUS_PAUSED, 'paused_reason' => $reason]);
    }
}
