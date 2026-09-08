<?php

namespace App\Support\Dns;

/**
 * Every DNS question this application asks.
 *
 * One interface rather than one per caller, though they ask different things
 * of the same service: the health checks read TXT and A records to see whether
 * our own sending domains are set up correctly, and the email waterfall asks
 * whether a recipient's domain has anywhere to deliver mail at all.
 *
 * Behind an interface so both can be tested without a network, and without a
 * suite whose results depend on somebody else's resolver.
 */
interface DnsResolver
{
    /** @return array<string> TXT record strings for the host */
    public function txtRecords(string $host): array;

    /** @return array<string> A record IPs for the host */
    public function aRecords(string $host): array;

    /** Whether the domain publishes anywhere to deliver mail. */
    public function hasMailExchanger(string $domain): bool;
}
