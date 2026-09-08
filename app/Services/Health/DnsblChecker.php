<?php

namespace App\Services\Health;

use App\Models\Domain;
use App\Models\HealthCheck;
use App\Support\Dns\DnsResolver;

/**
 * Checks the domain against domain-based blocklists (Spamhaus DBL, SURBL,
 * URIBL). Best-effort: some lists refuse queries via large public resolvers,
 * so a clean result is "not known-listed", not a guarantee.
 */
class DnsblChecker
{
    public function __construct(
        protected DnsResolver $resolver,
    ) {}

    public function check(Domain $domain): void
    {
        $listedZones = [];

        foreach (config('outreach.dnsbl_zones', []) as $zone) {
            $ips = $this->resolver->aRecords("{$domain->name}.{$zone}");

            // A 127.0.0.x answer means "listed". Spamhaus returns 127.255.x.x
            // error codes for blocked/over-quota resolvers — not a listing.
            $listed = collect($ips)->contains(
                fn (string $ip) => str_starts_with($ip, '127.') && ! str_starts_with($ip, '127.255.')
            );

            if ($listed) {
                $listedZones[] = $zone;
            }
        }

        HealthCheck::query()->create([
            'checkable_type' => $domain->getMorphClass(),
            'checkable_id' => $domain->id,
            'check_type' => 'dnsbl',
            'status' => $listedZones === [] ? HealthCheck::STATUS_OK : HealthCheck::STATUS_FAIL,
            'detail' => ['listed_zones' => $listedZones, 'zones_checked' => config('outreach.dnsbl_zones', [])],
            'checked_at' => now(),
        ]);

        $domain->update([
            'dnsbl_listed' => $listedZones !== [],
            'dnsbl_zones' => $listedZones ?: null,
        ]);

        if ($listedZones !== [] && $domain->health_status !== Domain::HEALTH_CRITICAL) {
            $domain->update(['health_status' => Domain::HEALTH_CRITICAL]);
        }
    }
}
