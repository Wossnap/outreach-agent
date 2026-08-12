<?php

namespace App\Services\Health;

class PhpDnsResolver implements DnsResolver
{
    public function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn (array $r) => $r['txt'] ?? '', $records);
    }

    public function aRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];

        return array_map(fn (array $r) => $r['ip'] ?? '', $records);
    }
}
