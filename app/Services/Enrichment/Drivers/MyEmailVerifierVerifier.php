<?php

namespace App\Services\Enrichment\Drivers;

use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Balance;
use App\Services\Enrichment\Contracts\EmailVerifier;
use App\Services\Enrichment\Contracts\PublishesListPrice;
use App\Services\Enrichment\Contracts\ReportsBalance;
use App\Services\Enrichment\ListPrice;
use App\Services\Enrichment\Verdict;
use App\Services\Enrichment\Verification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MyEmailVerifier.
 *
 * Read mostly through its boolean fields rather than its Status string.
 *
 * The published documentation shows one example status and does not enumerate
 * the rest, so keying the verdict on that string would mean guessing at values
 * this code has never seen. The booleans beside it are unambiguous and are
 * where the decisions that matter actually live: catch_all and greylisted both
 * mean the answer proves nothing, whatever the status says next to them.
 *
 * https://github.com/pat-myemailverifier/myemailverifier-api
 */
class MyEmailVerifierVerifier implements EmailVerifier, PublishesListPrice, ReportsBalance
{
    /** What this provider is called on screen. */
    public static function label(): string
    {
        return 'MyEmailVerifier';
    }

    public static function listPrice(): ListPrice
    {
        return new ListPrice(
            perLookup: 0.004,
            billedOnMiss: true,
            note: 'Pay-as-you-go, $4 for 1,000 credits, never expire; $15 for 10,000. From myemailverifier.com/pricing, 30 September 2026.',
        );
    }

    private const ENDPOINT = 'https://api.myemailverifier.com/api/validate_single.php';

    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        $response = Http::timeout((int) config('enrichment.timeout', 20))
            ->get(self::ENDPOINT, [
                'apikey' => $provider->credential('api_key'),
                'email' => $email,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'MyEmailVerifier returned '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['Status'])) {
            throw new RuntimeException(
                'MyEmailVerifier sent no verdict: '.mb_substr($response->body(), 0, 300)
            );
        }

        return new Verification($this->interpret($body), array_filter([
            'status' => $body['Status'] ?? null,
            // Their own words, and the most readable explanation any of the
            // providers give.
            'diagnosis' => $body['Diagnosis'] ?? null,
            'catch-all' => filter_var($body['catch_all'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'yes' : null,
            'role account' => filter_var($body['Role_Based'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'yes' : null,
            'greylisted' => filter_var($body['Greylisted'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'yes' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function interpret(array $body): Verdict
    {
        // Booleans arrive as the strings "true" and "false", so a plain cast
        // would make every one of them true.
        $flag = fn (string $key): bool => filter_var(
            $body[$key] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        // A throwaway address. Real today, gone next week, and never worth
        // contacting.
        if ($flag('Disposable_Domain')) {
            return Verdict::INVALID;
        }

        /*
         * Checked before the status, because on a catch-all domain the status
         * is meaningless: the server accepts every address it is offered.
         * Role accounts join them here. info@ is real and reaches somebody, it
         * is just not a person.
         */
        if ($flag('catch_all') || $flag('Role_Based')) {
            return Verdict::CATCH_ALL;
        }

        // The server deferred rather than answered. A fact about this moment,
        // not about the address, so it passes to whoever is next.
        if ($flag('Greylisted')) {
            return Verdict::UNKNOWN;
        }

        return match (mb_strtolower(trim((string) $body['Status']))) {
            'valid' => Verdict::VALID,
            'invalid' => Verdict::INVALID,

            // Anything else, including their documented "unknown" and any
            // status this code has not seen. Never valid by default.
            default => Verdict::UNKNOWN,
        };
    }

    /**
     * A different host from the one that verifies, and the key goes in the path
     * rather than a parameter.
     */
    private const BALANCE_ENDPOINT = 'https://client.myemailverifier.com/verifier/getcredits/';

    public function balance(EnrichmentProvider $provider): Balance
    {
        $response = Http::timeout((int) config('enrichment.balance_timeout', 8))
            ->get(self::BALANCE_ENDPOINT.rawurlencode((string) $provider->credential('api_key')));

        if ($response->failed()) {
            throw new RuntimeException(
                'MyEmailVerifier returned '.$response->status().': '.mb_substr($response->body(), 0, 200)
            );
        }

        /*
         * A key it does not recognise is answered with HTTP 200 and a redirect
         * to their login page, so a check on the status code alone would read a
         * page of HTML as an account.
         */
        if ($response->json('status') !== true) {
            throw new RuntimeException(
                'MyEmailVerifier did not accept the key: '.mb_substr($response->body(), 0, 200)
            );
        }

        $credits = $response->json('credits');

        if (! is_numeric($credits)) {
            throw new RuntimeException(
                'MyEmailVerifier did not report a balance: '.mb_substr($response->body(), 0, 200)
            );
        }

        return new Balance(remaining: (int) $credits, pool: 'credits');
    }
}
