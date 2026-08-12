<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    ];

    protected function casts(): array
    {
        return [
            'dnsbl_listed' => 'boolean',
            'dnsbl_zones' => 'array',
            'last_dns_checked_at' => 'datetime',
        ];
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function healthChecks(): MorphMany
    {
        return $this->morphMany(HealthCheck::class, 'checkable');
    }

    public function dkimSelector(): string
    {
        return $this->dkim_selector ?: config('outreach.dkim_default_selector');
    }
}
