<?php

namespace App\Services\Enrichment\Contracts;

use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\FoundEmail;

/**
 * Turns a person into an email address.
 *
 * Returning null means "I do not have one", which is ordinary and not an error:
 * the waterfall moves to the next provider. Throwing means the provider itself
 * is in trouble, which is a different thing and is handled differently.
 */
interface EmailFinder
{
    /**
     * Whether this provider has enough to work with.
     *
     * A finder that needs a company domain cannot do anything with a lead that
     * has only a LinkedIn URL. Asked before the call so the provider is skipped
     * rather than charged for a request never made, which would make its
     * cost-per-address look worse than it is.
     */
    public function supports(Contact $contact): bool;

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail;
}
