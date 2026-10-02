<?php

namespace App\Services\Enrichment\Drivers;

use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Balance;
use App\Services\Enrichment\Contracts\EmailVerifier;
use App\Services\Enrichment\Contracts\PublishesListPrice;
use App\Services\Enrichment\Contracts\ReportsBalance;
use App\Services\Enrichment\Contracts\ResolvesCatchAll;
use App\Services\Enrichment\ListPrice;
use App\Services\Enrichment\Verdict;
use App\Services\Enrichment\Verification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * BounceBan. The specialist, and the reason for having one.
 *
 * Every other verifier answers "catch-all" and stops, because on such a domain
 * the mail server accepts whatever it is offered. That is not a small category:
 * a fifth to a third of business domains are configured that way, and each one
 * is a lead we currently hold back from. This one goes further and says whether
 * that particular mailbox actually receives mail.
 *
 * Slow and paid, so it belongs last: the cheap verifiers settle most addresses
 * in a second or two, and this is only asked about the ones they could not.
 *
 * https://bounceban.com/public/doc/llms-full.txt
 */
class BounceBanVerifier implements EmailVerifier, PublishesListPrice, ReportsBalance, ResolvesCatchAll
{
    /** What this provider is called on screen. */
    public static function label(): string
    {
        return 'BounceBan';
    }

    public static function listPrice(): ListPrice
    {
        return new ListPrice(
            perLookup: 0.004,
            billedOnMiss: true,
            note: 'Pay-as-you-go, $40 for 10,000 credits, never expire; $34 a month on subscription. From bounceban.com/pricing, 30 September 2026.',
        );
    }

    /*
     * The waterfall host, not the standard one.
     *
     * It holds the connection open until the answer is ready rather than making
     * us poll, and re-asking the same address within thirty minutes is free.
     * That matters here: a lead checked again after a provider is reordered
     * would otherwise be paid for twice.
     */
    private const ENDPOINT = 'https://api-waterfall.bounceban.com/v1/verify/single';

    /**
     * Their own default, and not a setting.
     *
     * How long this provider takes is a fact about the provider. It holds the
     * connection open while it does the work, and the alternative to waiting is
     * a 408 and asking again.
     */
    private const WAIT_SECONDS = 80;

    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        $response = Http::withHeaders([
            // No Bearer prefix. The key goes in bare.
            'Authorization' => $provider->credential('api_key'),
        ])
            // Ten seconds beyond theirs, because theirs is the one that
            // decides. A client timeout shorter than the server's would abandon
            // an answer we had already paid for.
            ->timeout(self::WAIT_SECONDS + 10)
            ->get(self::ENDPOINT, [
                'email' => $email,
                'timeout' => self::WAIT_SECONDS,
            ]);

        /*
         * 408 means it is still working, not that it failed. The verification
         * carries on in the background and the same request within thirty
         * minutes returns it free of charge.
         *
         * Treated as no opinion rather than an error, so the lead falls through
         * to whoever is next instead of counting against this provider and
         * eventually switching it off for being slow.
         */
        if ($response->status() === 408) {
            return new Verification(Verdict::UNKNOWN, [
                'note' => 'Still verifying. Asking again within thirty minutes is free.',
                'id' => $response->json('id'),
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'BounceBan returned '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        if ($response->json('status') !== 'success') {
            throw new RuntimeException(
                'BounceBan did not succeed: '.mb_substr($response->body(), 0, 300)
            );
        }

        $body = $response->json();

        return new Verification($this->interpret($body), array_filter([
            'result' => $body['result'] ?? null,
            'score' => $body['score'] ?? null,
            // The field the other verifiers cannot see past.
            'accepts everything' => ($body['is_accept_all'] ?? false) ? 'yes' : null,
            'mail host' => $body['smtp_provider'] ?? null,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function interpret(array $body): Verdict
    {
        // A throwaway address, whatever it says about deliverability.
        if ($body['is_disposable'] ?? false) {
            return Verdict::INVALID;
        }

        return match ((string) ($body['result'] ?? '')) {
            'deliverable' => Verdict::VALID,

            // Their word for "will bounce, do not send". Note this is a real
            // answer about a catch-all domain, not a shrug at one, which is the
            // whole point of paying for this.
            'undeliverable' => Verdict::INVALID,

            // May bounce. Not sendable, and not dead either.
            'risky' => Verdict::CATCH_ALL,

            // Could not be determined, including their timeout case.
            'unknown' => Verdict::UNKNOWN,

            default => Verdict::UNKNOWN,
        };
    }

    /**
     * The standard host, not the waterfall one we verify against. The waterfall
     * host has no account endpoint: asking it for /v1/credits falls through to
     * the verify route, which answers 400 asking for an email address.
     */
    private const BALANCE_ENDPOINT = 'https://api.bounceban.com/v1/account';

    public function balance(EnrichmentProvider $provider): Balance
    {
        $response = Http::withHeaders([
            // Bare, as everywhere else with BounceBan. No Bearer prefix.
            'Authorization' => $provider->credential('api_key'),
        ])
            ->timeout((int) config('enrichment.balance_timeout', 8))
            ->get(self::BALANCE_ENDPOINT);

        if ($response->failed()) {
            throw new RuntimeException(
                'BounceBan returned '.$response->status().': '.mb_substr($response->body(), 0, 200)
            );
        }

        $credits = $response->json('available_credits');

        if (! is_numeric($credits)) {
            throw new RuntimeException(
                'BounceBan did not report a balance: '.mb_substr($response->body(), 0, 200)
            );
        }

        return new Balance(remaining: (int) $credits, pool: 'credits');
    }
}
