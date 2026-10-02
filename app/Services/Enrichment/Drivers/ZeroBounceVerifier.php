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
 * ZeroBounce.
 *
 * A second opinion, and the point of having one: when the provider ahead of it
 * will not commit, the address falls through to here rather than being written
 * off as unconfirmed. Greylisting and timeouts are the usual reason, and they
 * are properties of the moment rather than of the address.
 *
 * https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-validate-emails/
 */
class ZeroBounceVerifier implements EmailVerifier, PublishesListPrice, ReportsBalance
{
    /** What this provider is called on screen. */
    public static function label(): string
    {
        return 'ZeroBounce';
    }

    public static function listPrice(): ListPrice
    {
        return new ListPrice(
            perLookup: 0.0195,
            billedOnMiss: true,
            note: 'Pay-as-you-go minimum, $39 for 2,000 credits. From zerobounce.net/email-validation-pricing, 30 September 2026.',
        );
    }

    private const ENDPOINT = 'https://api.zerobounce.net/v2/validate';

    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        $response = Http::timeout((int) config('enrichment.timeout', 20))
            ->get(self::ENDPOINT, [
                'api_key' => $provider->credential('api_key'),
                'email' => $email,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'ZeroBounce returned '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        // An exhausted account answers 200 with an error in the body, so the
        // status code alone would let a dead key look like a provider that
        // simply never has an opinion.
        if (filled($error = $response->json('error'))) {
            throw new RuntimeException('ZeroBounce: '.mb_substr((string) $error, 0, 300));
        }

        $status = (string) $response->json('status');

        return new Verification($this->interpret($status), array_filter([
            'status' => $status,
            // Their granular reason: greylisted, timeout_exceeded, role_based
            // and so on. Usually the only thing that explains an unknown.
            'reason' => $response->json('sub_status'),
        ]));
    }

    private function interpret(string $status): Verdict
    {
        return match (mb_strtolower($status)) {
            'valid' => Verdict::VALID,

            /*
             * All four are addresses never to send to, for different reasons.
             *
             * A spamtrap exists to catch senders who bought a list, and mail to
             * one damages the sending domain far more than a bounce. Abuse
             * marks somebody who reports mail as spam. do_not_mail covers
             * addresses that should not receive at all.
             */
            'invalid', 'spamtrap', 'abuse', 'do_not_mail' => Verdict::INVALID,

            // Accepts everything it is offered, so it has said nothing about
            // this address.
            'catch-all', 'catch_all' => Verdict::CATCH_ALL,

            // Greylisted or timed out. A fact about this moment rather than
            // about the address, so it passes to whoever is next.
            'unknown' => Verdict::UNKNOWN,

            // Never valid by default: being wrong that way sends mail to an
            // address nobody confirmed.
            default => Verdict::UNKNOWN,
        };
    }

    private const BALANCE_ENDPOINT = 'https://api.zerobounce.net/v2/getcredits';

    public function balance(EnrichmentProvider $provider): Balance
    {
        $response = Http::timeout((int) config('enrichment.balance_timeout', 8))
            ->get(self::BALANCE_ENDPOINT, ['api_key' => $provider->credential('api_key')]);

        if ($response->failed()) {
            throw new RuntimeException(
                'ZeroBounce returned '.$response->status().': '.mb_substr($response->body(), 0, 200)
            );
        }

        $credits = $response->json('Credits');

        if (! is_numeric($credits)) {
            throw new RuntimeException(
                'ZeroBounce did not report a balance: '.mb_substr($response->body(), 0, 200)
            );
        }

        /*
         * A key ZeroBounce does not recognise is answered with HTTP 200 and
         * minus one credit, in exactly the shape of a real balance. Taken at
         * face value that is an account with less than nothing in it, which
         * would show as an empty account rather than a rejected key: one of
         * those is fixed by buying credit and the other by fixing the key.
         */
        if ((int) $credits < 0) {
            throw new RuntimeException(
                'ZeroBounce returned '.$credits.' credits, which is what it reports for a key it does not recognise.'
            );
        }

        return new Balance(remaining: (int) $credits, pool: 'credits');
    }
}
