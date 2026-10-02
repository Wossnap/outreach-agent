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
 * Reoon's email verifier.
 *
 * Does the SMTP handshake from its own infrastructure, which is why we do not
 * do it from ours: most hosts block outbound port 25 by default, so a
 * self-hosted handshake would have to be argued for with whoever runs the
 * server before it could work at all.
 *
 * https://www.reoon.com/articles/api-documentation-of-reoon-email-verifier/
 */
class ReoonVerifier implements EmailVerifier, PublishesListPrice, ReportsBalance
{
    /** What this provider is called on screen. */
    public static function label(): string
    {
        return 'Reoon';
    }

    public static function listPrice(): ListPrice
    {
        return new ListPrice(
            perLookup: 0.00119,
            billedOnMiss: true,
            note: '$11.90 for 10,000 instant credits, one-off, never expire. From reoon.com/email-verifier, 30 September 2026.',
        );
    }

    private const ENDPOINT = 'https://emailverifier.reoon.com/api/v1/verify';

    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        $response = Http::timeout((int) config('enrichment.timeout', 20))
            ->get(self::ENDPOINT, [
                'email' => $email,
                /*
                 * Always power, never quick, and not configurable.
                 *
                 * Their own documentation is blunt about why: in quick mode
                 * "all emails including non-existing ones from that domain will
                 * be marked as valid". It checks the domain, not the mailbox.
                 *
                 * A dead address would come back as a confident yes, which is
                 * the failure the verify step exists to catch. Quick mode is
                 * faster and cheaper, so it is not offered as an option.
                 */
                'mode' => 'power',
                'key' => $provider->credential('api_key'),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Reoon returned '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        $status = (string) $response->json('status');

        if ($status === 'error') {
            throw new RuntimeException('Reoon: '.mb_substr((string) $response->json('reason'), 0, 300));
        }

        return new Verification($this->interpret($status), array_filter([
            'status' => $status,
            'score' => $response->json('overall_score'),
            'safe to send' => $response->json('is_safe_to_send'),
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Anything not recognised is UNKNOWN, deliberately.
     *
     * A status this code has not seen before must never fall through to valid.
     * Being wrong in that direction sends mail to an address nobody confirmed,
     * which is the failure this whole system exists to prevent; being wrong the
     * other way costs one more lookup.
     */
    private function interpret(string $status): Verdict
    {
        return match (mb_strtolower($status)) {
            // "valid" comes back from quick mode, "safe" from power mode.
            'valid', 'safe' => Verdict::VALID,

            // Dead for good. Disabled is a closed account, disposable is a
            // throwaway, and a spamtrap is worse than useless: mail to one is
            // what gets a sending domain blacklisted.
            'invalid', 'disabled', 'disposable', 'spamtrap' => Verdict::INVALID,

            // Accepts every address it is offered, so it has told us nothing
            // about this one. Role accounts are grouped here rather than with
            // invalid: info@ is real and reaches somebody, it is just not a
            // person and not worth cold emailing.
            'catch_all', 'role_account' => Verdict::CATCH_ALL,

            /*
             * The mailbox exists and is full. Mail sent now would bounce, so it
             * is not sendable, but it is not dead either and the box may be
             * emptied tomorrow. Deliberately left unresolved rather than marked
             * invalid: a settled lead is never looked at again, and a temporary
             * condition should not lose a real person for good.
             */
            'inbox_full' => Verdict::UNKNOWN,

            // Anything this code has not seen. Never valid: being wrong that
            // way sends mail to an address nobody confirmed.
            default => Verdict::UNKNOWN,
        };
    }

    private const BALANCE_ENDPOINT = 'https://emailverifier.reoon.com/api/v1/check-account-balance/';

    /**
     * Reoon holds two balances, and the mode we run decides which one is spent.
     *
     * Daily credits refill every day and are what quick mode spends. Instant
     * credits do not refill and are what power mode spends. We hardcode power
     * mode, because quick mode calls every address at a working domain valid,
     * so the instant pool is the one that runs out and stops us.
     *
     * The two are close enough in size to be mistaken for each other, and the
     * daily figure is the one that looks reassuring while meaning nothing here.
     */
    public function balance(EnrichmentProvider $provider): Balance
    {
        $response = Http::timeout((int) config('enrichment.balance_timeout', 8))
            ->get(self::BALANCE_ENDPOINT, ['key' => $provider->credential('api_key')]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Reoon returned '.$response->status().': '.mb_substr($response->body(), 0, 200)
            );
        }

        if ($response->json('api_status') !== 'active') {
            throw new RuntimeException(
                'Reoon reports the key as '.($response->json('api_status') ?? 'unreadable').' rather than active.'
            );
        }

        $instant = $response->json('remaining_instant_credits');

        if (! is_numeric($instant)) {
            throw new RuntimeException(
                'Reoon did not report an instant credit balance: '.mb_substr($response->body(), 0, 200)
            );
        }

        $daily = $response->json('remaining_daily_credits');

        return new Balance(
            remaining: (int) $instant,
            pool: 'instant credits',
            untouched: is_numeric($daily)
                ? ['daily credits' => (int) $daily]
                : [],
        );
    }
}
