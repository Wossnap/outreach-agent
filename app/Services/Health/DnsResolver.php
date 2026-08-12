<?php

namespace App\Services\Health;

interface DnsResolver
{
    /** @return array<string> TXT record strings for the host */
    public function txtRecords(string $host): array;

    /** @return array<string> A record IPs for the host */
    public function aRecords(string $host): array;
}
