<?php

namespace App\Services\Enrichment;

use Carbon\CarbonImmutable;

/**
 * One attempt at reading a provider's balance, successful or not.
 *
 * Checking a balance is a live call over the network to somebody else's
 * service, so it fails in all the ordinary ways. The failure is carried back
 * rather than thrown, because six providers are read to draw one page and one
 * of them being down should cost that row its figure, not the whole page.
 *
 * The time is kept because the figure is cached, and a number with no age on it
 * invites someone to read a ten minute old balance as the balance now.
 *
 * Travels through the cache as a plain array rather than as itself. PHP's
 * unserialize() only autoloads when unserialize_callback_func is set, and it is
 * empty in the stock php.ini this runs on, so an object read back by a process
 * that has not already loaded its class arrives as __PHP_Incomplete_Class.
 * A page that had never touched this class was the first thing to read one of
 * these back, and it fell over. Arrays of scalars have no such problem, and a
 * cache written before a class changed shape cannot poison the process either.
 */
readonly class BalanceReading
{
    public function __construct(
        public ?Balance $balance,
        public ?string $error,
        public CarbonImmutable $checkedAt,
    ) {}

    public static function of(Balance $balance): self
    {
        return new self($balance, null, CarbonImmutable::now());
    }

    public static function failed(string $error): self
    {
        return new self(null, mb_substr($error, 0, 300), CarbonImmutable::now());
    }

    public function succeeded(): bool
    {
        return $this->balance !== null;
    }

    /** @return array<string, mixed>  scalars and arrays only */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance === null ? null : [
                'remaining' => $this->balance->remaining,
                'pool' => $this->balance->pool,
                'untouched' => $this->balance->untouched,
            ],
            'error' => $this->error,
            'checked_at' => $this->checkedAt->getTimestamp(),
        ];
    }

    /**
     * Rebuild one, or null if this is not something we wrote.
     *
     * Null rather than an exception, so a reading left by an older shape of
     * this class is treated as nothing cached and read again, instead of
     * breaking every page until somebody clears the cache by hand.
     *
     * @param  mixed  $state  whatever came out of the cache
     */
    public static function tryFromArray(mixed $state): ?self
    {
        if (! is_array($state) || ! array_key_exists('balance', $state) || ! isset($state['checked_at'])) {
            return null;
        }

        $balance = $state['balance'];

        if ($balance !== null && ! isset($balance['remaining'], $balance['pool'])) {
            return null;
        }

        return new self(
            balance: $balance === null ? null : new Balance(
                remaining: (int) $balance['remaining'],
                pool: (string) $balance['pool'],
                untouched: (array) ($balance['untouched'] ?? []),
            ),
            error: $state['error'] ?? null,
            checkedAt: CarbonImmutable::createFromTimestamp($state['checked_at']),
        );
    }
}
