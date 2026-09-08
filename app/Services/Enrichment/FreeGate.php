<?php

namespace App\Services\Enrichment;

use App\Support\Dns\DnsResolver;
use Illuminate\Support\Str;

/**
 * The checks that cost nothing, run before any provider is paid.
 *
 * An address that is malformed, or whose domain publishes nowhere to deliver
 * mail, cannot receive anything. Establishing that here means never spending a
 * lookup on it. It is the cheapest part of the waterfall and it removes the
 * most obviously dead addresses.
 */
class FreeGate
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function passes(string $email): bool
    {
        return $this->looksLikeAnAddress($email) && $this->domainAcceptsMail($email);
    }

    public function looksLikeAnAddress(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function domainAcceptsMail(string $email): bool
    {
        return $this->dns->hasMailExchanger(Str::after($email, '@'));
    }
}
