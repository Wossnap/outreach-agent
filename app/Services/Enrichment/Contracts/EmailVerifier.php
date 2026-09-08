<?php

namespace App\Services\Enrichment\Contracts;

use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Verification;

/**
 * Decides whether an address can actually receive mail.
 *
 * Returning UNKNOWN is a legitimate answer and passes the address to the next
 * verifier. Throwing means the provider is in trouble.
 *
 * The answer carries what the provider reported alongside the verdict, because
 * a verdict nobody can see the reasoning behind is one nobody can argue with.
 */
interface EmailVerifier
{
    public function verify(string $email, EnrichmentProvider $provider): Verification;
}
