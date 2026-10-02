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
use App\Services\Enrichment\Verdict;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hunter's Email Finder.
 *
 * Guesses an address from a LinkedIn handle where there is one, or from a
 * person's name and their company's domain otherwise, then scores how
 * confident it is. It does not verify: a found address still goes through the
 * verify half of the waterfall before anything is sent to it.
 *
 * https://hunter.io/api-documentation
 */
class HunterFinder implements EmailFinder, PublishesListPrice, ReportsBalance
{
    /** What this provider is called on screen. */
    public static function label(): string
    {
        return 'Hunter';
    }

    public static function listPrice(): ListPrice
    {
        return new ListPrice(
            perLookup: 0.0245,
            billedOnMiss: false,
            note: 'Starter plan, $49 a month for 2,000 credits, one credit per address found and none when nothing is found. From hunter.io/pricing, 30 September 2026.',
        );
    }

    private const ENDPOINT = 'https://api.hunter.io/v2/email-finder';

    /**
     * A lead is searchable here two ways: by a LinkedIn handle on its own, or
     * by domain and name. Either is enough, which reaches a lead that has a
     * profile but no company behind it.
     */
    public function supports(Contact $contact): bool
    {
        return (filled($this->domainFor($contact)) && filled($contact->name))
            || filled($this->handleFor($contact));
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        /*
         * The handle first, whenever there is one, even if a domain is also
         * present. A name and a domain can match more than one person; a
         * LinkedIn handle cannot, so it is the better search whenever it is
         * available at all.
         */
        $params = filled($this->handleFor($contact))
            ? ['linkedin_handle' => $this->handleFor($contact)]
            : ['domain' => $this->domainFor($contact), 'full_name' => $contact->name];

        $response = Http::timeout((int) config('enrichment.timeout', 20))
            ->get(self::ENDPOINT, $params + ['api_key' => $provider->credential('api_key')]);

        /*
         * A 404 means "no address for this person", which is an ordinary miss.
         * Anything else in the 400s is our problem (a bad key, no credit left)
         * and has to surface as a failure, or a provider whose account has
         * lapsed looks like one that simply never finds anybody.
         */
        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Hunter returned '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        $email = $response->json('data.email');

        if (blank($email)) {
            return null;
        }

        return new FoundEmail(
            email: $email,
            confidence: $response->json('data.score'),
            detail: array_filter([
                'score' => $response->json('data.score'),
                'sources' => count($response->json('data.sources', [])),
                // Kept so a verdict taken from Hunter can be traced to the day
                // Hunter checked it, which is not always the day we asked.
                'verification' => $response->json('data.verification.status'),
                'verified_on' => $response->json('data.verification.date'),
            ], fn ($value) => $value !== null),
            verdict: $this->verdictFrom($response->json('data.verification.status')),
            extra: array_filter([
                'score' => $response->json('data.score'),
                'sources' => count($response->json('data.sources', [])) ?: null,
                'position' => $response->json('data.position'),
                'linkedin_url' => $response->json('data.linkedin_url'),
                'twitter' => $response->json('data.twitter'),
                'domain' => $response->json('data.domain'),
                'company' => $response->json('data.company'),
            ]),
        );
    }

    /**
     * Hunter checks every address it finds, at no extra cost, and says so.
     *
     * Its finder only ever answers valid, accept_all or unknown: it does not
     * hand back an address it knows is dead. Anything it has not said is no
     * verdict at all, and the address goes through the verifiers as usual.
     */
    private function verdictFrom(?string $status): ?Verdict
    {
        return match ($status) {
            'valid' => Verdict::VALID,
            'accept_all' => Verdict::CATCH_ALL,
            'unknown' => Verdict::UNKNOWN,
            default => null,
        };
    }

    /**
     * The company's domain, which is what Hunter searches by.
     *
     * A lead that already has an address has a domain derived from it, and one
     * submitted with a company website has it directly. Neither is guaranteed,
     * which is what supports() is for.
     */
    private function domainFor(Contact $contact): ?string
    {
        return $contact->domain;
    }

    /**
     * The slug Hunter wants, out of a full LinkedIn URL.
     *
     * https://www.linkedin.com/in/sam-carter-4b2 becomes sam-carter-4b2.
     */
    private function handleFor(Contact $contact): ?string
    {
        $profile = $contact->linkedinProfileUrl();

        if ($profile === null) {
            return null;
        }

        return Str::of($profile)->after('/in/')->before('?')->rtrim('/')->value() ?: null;
    }

    private const BALANCE_ENDPOINT = 'https://api.hunter.io/v2/account';

    /**
     * Hunter meters searching and verifying separately, and we only ever search.
     *
     * The same response carries two other figures that look like the answer and
     * are not. "credits" happens to equal the searches figure today and is not
     * documented to stay that way, and "calls" arrives with a note from Hunter
     * itself calling it imprecise and deprecated. The searches pool is the one
     * an email-finder call spends.
     */
    public function balance(EnrichmentProvider $provider): Balance
    {
        $response = Http::timeout((int) config('enrichment.balance_timeout', 8))
            ->get(self::BALANCE_ENDPOINT, ['api_key' => $provider->credential('api_key')]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Hunter returned '.$response->status().': '.mb_substr($response->body(), 0, 200)
            );
        }

        $searches = $response->json('data.requests.searches.remaining');

        if (! is_numeric($searches)) {
            throw new RuntimeException(
                'Hunter did not report a searches balance: '.mb_substr($response->body(), 0, 200)
            );
        }

        $verifications = $response->json('data.requests.verifications.remaining');

        return new Balance(
            remaining: (int) $searches,
            pool: 'searches',
            // Checked rather than cast, because a missing field cast to an
            // integer is zero, which would read as a used-up pool.
            untouched: is_numeric($verifications)
                ? ['verifications' => (int) $verifications]
                : [],
        );
    }
}
