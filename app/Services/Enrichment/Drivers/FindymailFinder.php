<?php

namespace App\Services\Enrichment\Drivers;

use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Balance;
use App\Services\Enrichment\Contracts\EmailFinder;
use App\Services\Enrichment\Contracts\PublishesListPrice;
use App\Services\Enrichment\Contracts\ReportsBalance;
use App\Services\Enrichment\FoundEmail;
use App\Services\Enrichment\ListPrice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Findymail.
 *
 * Searches by a LinkedIn URL where there is one, or by full name and company
 * domain otherwise, and bills a credit only when it returns something. It
 * verifies deliverability before answering, which does not excuse our own
 * verify step: a second opinion on an address is the cheapest part of the
 * chain, and the two disagreeing is worth knowing.
 *
 * https://www.findymail.com/api/email-finder/
 */
class FindymailFinder implements EmailFinder, PublishesListPrice, ReportsBalance
{
    /** What this provider is called on screen. */
    public static function label(): string
    {
        return 'Findymail';
    }

    public static function listPrice(): ListPrice
    {
        return new ListPrice(
            perLookup: 0.049,
            billedOnMiss: false,
            note: 'About $0.049 per address on the starting plan, from a third-party comparison rather than Findymail directly, September 2026. Billed only when an address is returned.',
        );
    }

    private const ENDPOINT = 'https://app.findymail.com/api/search/name';

    /**
     * A separate endpoint that searches by LinkedIn URL alone. Same response
     * shape as the name search, so it is read the same way below.
     */
    private const BUSINESS_PROFILE_ENDPOINT = 'https://app.findymail.com/api/search/business-profile';

    /**
     * A lead is searchable here two ways: by a LinkedIn URL on its own, or by
     * domain and name. Either is enough, which reaches a lead that has a
     * profile but no company behind it.
     */
    public function supports(Contact $contact): bool
    {
        return filled($contact->linkedinProfileUrl()) || (filled($contact->domain) && filled($contact->name));
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        /*
         * The URL first, whenever there is one, even if a domain is also
         * present. A name and a domain can match more than one person; a
         * LinkedIn URL cannot, so it is the better search whenever it is
         * available at all.
         */
        [$endpoint, $params] = filled($contact->linkedinProfileUrl())
            ? [self::BUSINESS_PROFILE_ENDPOINT, ['linkedin_url' => $contact->linkedinProfileUrl()]]
            : [self::ENDPOINT, ['name' => $contact->name, 'domain' => $contact->domain]];

        $response = Http::withToken($provider->credential('api_key'))
            ->timeout((int) config('enrichment.timeout', 20))
            ->post($endpoint, $params);

        // No address for this person is an ordinary miss, and some providers
        // say so with a 404 rather than an empty body.
        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Findymail returned '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        $email = $response->json('contact.email');

        if (blank($email)) {
            return null;
        }

        return new FoundEmail(
            email: $email,
            // No confidence score is published, and inventing one would make
            // this provider look more or less certain than it claims to be.
            confidence: null,
            detail: array_filter([
                'name' => $response->json('contact.name'),
                'domain' => $response->json('contact.domain'),
            ]),
            // Everything else it told us about the person. Kept because a job
            // title or a location is worth having and costs nothing extra.
            extra: array_filter([
                'job_title' => $response->json('contact.job_title'),
                'company' => $response->json('contact.company'),
                'domain' => $response->json('contact.domain'),
                'linkedin_url' => $response->json('contact.linkedin_url'),
                'city' => $response->json('contact.city'),
                'region' => $response->json('contact.region'),
                'country' => $response->json('contact.country'),
            ]),
        );
    }

    private const BALANCE_ENDPOINT = 'https://app.findymail.com/api/credits';

    /**
     * Findymail sells finding and verifying out of two separate pots.
     *
     * We only ever use it to find, so verifier_credits is credit we hold and
     * cannot spend through this system. Shown, so nobody reads the account
     * total on their dashboard and wonders why the waterfall stopped.
     */
    public function balance(EnrichmentProvider $provider): Balance
    {
        $response = Http::withToken($provider->credential('api_key'))
            ->acceptJson()
            ->timeout((int) config('enrichment.balance_timeout', 8))
            ->get(self::BALANCE_ENDPOINT);

        if ($response->failed()) {
            throw new RuntimeException(
                'Findymail returned '.$response->status().': '.mb_substr($response->body(), 0, 200)
            );
        }

        $credits = $response->json('credits');

        if (! is_numeric($credits)) {
            throw new RuntimeException(
                'Findymail did not report a balance: '.mb_substr($response->body(), 0, 200)
            );
        }

        $verifier = $response->json('verifier_credits');

        return new Balance(
            remaining: (int) $credits,
            pool: 'finder credits',
            untouched: is_numeric($verifier)
                ? ['verifier credits' => (int) $verifier]
                : [],
        );
    }
}
