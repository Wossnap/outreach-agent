<?php

namespace App\Services\Enrichment\Contracts;

use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Balance;

/**
 * A driver that can ask its provider what is left on the account.
 *
 * Optional, in the same way a published price is: a provider we have not found
 * a balance endpoint for should say nothing rather than show a zero, which
 * would read as an empty account rather than an unanswered question.
 *
 * Implementations throw on anything they cannot read as a balance, including a
 * response that arrives with HTTP 200 and a refusal in the body. A provider
 * that cannot say how much credit it has is not a provider with no credit.
 */
interface ReportsBalance
{
    public function balance(EnrichmentProvider $provider): Balance;
}
