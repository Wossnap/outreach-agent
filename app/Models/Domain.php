<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Domain extends Model
{
    use HasFactory;

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_WARNING = 'warning';

    public const HEALTH_CRITICAL = 'critical';

    public const HEALTH_UNKNOWN = 'unknown';

    protected $fillable = [
        'name', 'dkim_selector', 'spf_status', 'dkim_status', 'dmarc_status',
        'dnsbl_listed', 'dnsbl_zones', 'health_status', 'last_dns_checked_at',
        'postmaster_synced_at', 'postmaster_error', 'postmaster_verification', 'postmaster_compliance',
    ];

    protected function casts(): array
    {
        return [
            'dnsbl_listed' => 'boolean',
            'dnsbl_zones' => 'array',
            'last_dns_checked_at' => 'datetime',
            'postmaster_synced_at' => 'datetime',
            'postmaster_compliance' => 'array',
        ];
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function postmasterStats(): HasMany
    {
        return $this->hasMany(PostmasterStat::class);
    }

    /** The most recent day Gmail reported anything for this domain. */
    public function latestPostmasterStat(): HasOne
    {
        return $this->hasOne(PostmasterStat::class)->latestOfMany('date');
    }

    public function healthChecks(): MorphMany
    {
        return $this->morphMany(HealthCheck::class, 'checkable');
    }

    public function dkimSelector(): string
    {
        return $this->dkim_selector ?: config('outreach.dkim_default_selector');
    }

    /**
     * Mailboxes on this domain that could actually send.
     *
     * A domain whose only mailbox is disconnected sends nothing, so its DNS
     * cannot affect anything and it should not be scored as though it could.
     */
    public function sendingMailboxCount(): int
    {
        return $this->mailboxes
            ->reject(fn (Mailbox $m) => $m->status === Mailbox::STATUS_DISCONNECTED)
            ->count();
    }

    /** DMARC states that are fine. Neither of them affects delivery. */
    public const DMARC_OK_STATUSES = ['monitoring', 'enforcing', 'ok'];

    /**
     * Human label for a stored check result.
     *
     * DMARC gets a fuller label because "ok" alone hides the difference
     * between monitoring and enforcing, which is the one thing worth knowing,
     * and because "absent" needs to read as a note rather than a fault.
     */
    public function statusLabel(string $field): string
    {
        $value = (string) $this->{$field};

        if ($field !== 'dmarc_status') {
            return $value;
        }

        return match ($value) {
            'absent' => 'none set',
            'monitoring' => 'ok (monitoring)',
            'enforcing' => 'ok (enforcing)',
            default => $value,
        };
    }

    /** True when this field should read as a problem rather than a note. */
    public function statusIsFault(string $field): bool
    {
        $value = (string) $this->{$field};

        if ($field === 'dmarc_status') {
            // Nothing about DMARC blocks sending, so nothing here is a fault.
            return false;
        }

        return in_array($value, ['missing', 'warn'], true);
    }
}
