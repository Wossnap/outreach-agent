<?php

namespace App\Support\Dns;

/**
 * DNS lookups through PHP's own resolver functions.
 *
 * The @ on each dns_get_record call suppresses PHP warnings. A host with no
 * records of the type asked for raises a warning and returns false, and Laravel
 * turns PHP warnings into thrown exceptions. "No records" is an ordinary answer
 * to both questions this class is asked, so it comes back as an empty array
 * rather than as an exception.
 */
class PhpDnsResolver implements DnsResolver
{
    /** Whether this machine's resolver answers for domains that do not exist. */
    private ?bool $resolverInventsAnswers = null;

    /** @return array<string> */
    public function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn (array $r) => $r['txt'] ?? '', $records);
    }

    /** @return array<string> */
    public function aRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];

        return array_map(fn (array $r) => $r['ip'] ?? '', $records);
    }

    public function hasMailExchanger(string $domain): bool
    {
        if (trim($domain) === '') {
            return false;
        }

        if (checkdnsrr($domain, 'MX')) {
            return true;
        }

        /*
         * An address record counts as somewhere to deliver: a domain with no MX
         * but an A record is still a legal mail destination under RFC 5321, and
         * real small businesses are set up that way. Rejecting those would throw
         * away good leads to save a fraction of a penny.
         *
         * Only where the answer can be believed, though. Plenty of resolvers
         * answer every unknown name with an address of their own rather than
         * saying it does not exist, and on one of those this fallback would
         * pass every dead domain ever submitted. Common enough that it is
         * measured rather than assumed away.
         */
        return ! $this->resolverInventsAnswers() && checkdnsrr($domain, 'A');
    }

    /**
     * Ask for a name that cannot exist and see whether an answer comes back.
     *
     * .example is reserved by RFC 2606 and is never registered, so anything but
     * "no such domain" means the resolver is making things up. Random, so a
     * cached answer from an earlier run cannot decide it, and remembered for the
     * life of the process rather than asked once per lead.
     */
    private function resolverInventsAnswers(): bool
    {
        return $this->resolverInventsAnswers ??= checkdnsrr(
            'no-such-host-'.bin2hex(random_bytes(8)).'.example',
            'A',
        );
    }
}
