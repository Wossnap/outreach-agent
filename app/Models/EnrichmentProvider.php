<?php

namespace App\Models;

use App\Services\Enrichment\BalanceReading;
use App\Services\Enrichment\Contracts\EmailFinder;
use App\Services\Enrichment\Contracts\EmailVerifier;
use App\Services\Enrichment\Contracts\PublishesListPrice;
use App\Services\Enrichment\Contracts\ReportsBalance;
use App\Services\Enrichment\ListPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class EnrichmentProvider extends Model
{
    /** Turns a person into an email address. */
    public const KIND_FIND = 'find';

    /** Decides whether an address can receive mail. */
    public const KIND_VERIFY = 'verify';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            // Encrypted at rest: these are live billing credentials, and there
            // is no reason for anyone reading the database to see them.
            'credentials' => 'encrypted:array',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_FIND => 'Find an address',
            self::KIND_VERIFY => 'Verify an address',
        ];
    }

    /**
     * Which step a driver belongs to, worked out from the driver itself.
     *
     * A finder and a verifier are different interfaces, so the class already
     * says which it is and nobody has to be asked. Choosing it by hand only
     * ever created the chance of choosing wrongly: a verifier set to find would
     * fail on every lead it touched until it switched itself off.
     */
    public static function kindForDriver(?string $driver): ?string
    {
        $class = config("enrichment.drivers.{$driver}");

        if (! $class || ! class_exists($class)) {
            return null;
        }

        return match (true) {
            is_a($class, EmailFinder::class, true) => self::KIND_FIND,
            is_a($class, EmailVerifier::class, true) => self::KIND_VERIFY,
            default => null,
        };
    }

    /**
     * @return array<string, string> driver key => the step it belongs to
     */
    public static function driversByKind(): array
    {
        return collect(array_keys(config('enrichment.drivers', [])))
            ->mapWithKeys(fn (string $driver): array => [$driver => self::kindForDriver($driver)])
            ->filter()
            ->all();
    }

    protected static function booted(): void
    {
        // Enforced on the way in, so a row written from a console or a seeder
        // cannot end up mismatched either.
        static::saving(function (self $provider): void {
            $provider->kind = self::kindForDriver($provider->driver) ?? $provider->kind;
        });

        /*
         * A balance is cached for ten minutes, which is the wrong thing to do
         * to somebody who has just pasted in a corrected key: they would look
         * at the failure they came to fix. Editing a row throws its reading
         * away, so the next page load asks again.
         */
        static::saved(fn (self $provider) => $provider->forgetBalance());
    }

    public function lookups(): HasMany
    {
        return $this->hasMany(EmailLookup::class);
    }

    /**
     * The order they are tried in, which is also the order they are shown in.
     *
     * One clause in one place, so a settings page can never list providers in
     * a different order from the one the waterfall actually uses.
     */
    public function scopeInPositionOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Switched on, for one half of the waterfall, in the order tried. */
    public function scopeInWaterfall(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind)
            ->where('enabled', true)
            ->inPositionOrder();
    }

    /**
     * The class that does the work, resolved from the container so it can take
     * dependencies and be swapped in tests.
     */
    public function driver(): object
    {
        $class = config("enrichment.drivers.{$this->driver}");

        if (! $class) {
            throw new RuntimeException("No driver registered for [{$this->driver}]. See config/enrichment.php.");
        }

        return app($class);
    }

    /**
     * What the provider publishes as its rate, if the driver knows.
     *
     * Only a starting point. The figure on the row stays editable because the
     * rate you actually pay depends on the plan and the volume tier, and those
     * differ by a factor of ten at the same provider.
     */
    public static function listPriceForDriver(?string $driver): ?ListPrice
    {
        $class = config("enrichment.drivers.{$driver}");

        if (! $class || ! class_exists($class) || ! is_a($class, PublishesListPrice::class, true)) {
            return null;
        }

        return $class::listPrice();
    }

    /**
     * What one call to this provider costs.
     *
     * The driver's, not the row's. A price belongs to the provider, so it is
     * stated once beside the code that talks to them and every account using
     * that driver reports the same figure. Nothing to fill in, and nothing to
     * fill in wrongly.
     */
    public function costPerLookup(): float
    {
        return self::listPriceForDriver($this->driver)?->perLookup ?? 0.0;
    }

    /** Whether a call that finds nothing is billed anyway. */
    public function chargesOnMiss(): bool
    {
        return self::listPriceForDriver($this->driver)?->billedOnMiss ?? true;
    }

    /** Whether we know how to ask this provider what is left on the account. */
    public static function reportsBalanceForDriver(?string $driver): bool
    {
        $class = config("enrichment.drivers.{$driver}");

        return $class && class_exists($class) && is_a($class, ReportsBalance::class, true);
    }

    /**
     * Whether it is worth asking this provider at all.
     *
     * Two different reasons not to: no balance endpoint has been written for
     * this driver, or there is no key to ask with. Both mean there is nothing
     * to say, as opposed to a check that failed or an account that is empty.
     */
    public function canReportBalance(): bool
    {
        return self::reportsBalanceForDriver($this->driver) && $this->isConfigured() && $this->exists;
    }

    /**
     * What this provider had left, the last time somebody asked.
     *
     * Reads what is stored and never calls anybody. Drawing the waterfall page
     * used to ask all six providers over the network, so opening it waited on
     * the slowest of them, and a provider having a bad afternoon made the page
     * feel broken. Now the page is drawn from what is on hand and asking is a
     * button.
     *
     * Null means nobody has asked yet, which the page says in those words. It
     * is not the same as a figure of zero.
     */
    public function cachedBalance(): ?BalanceReading
    {
        if (! $this->canReportBalance()) {
            return null;
        }

        /*
         * What comes back has to be something this version of the code wrote.
         * A reading left by an older shape of the class is treated as nothing
         * stored, rather than breaking every page until somebody clears the
         * cache by hand.
         */
        return BalanceReading::tryFromArray(Cache::get($this->balanceCacheKey()));
    }

    /**
     * Ask the provider now, and keep the answer.
     *
     * Kept until somebody asks again or the key changes, with no expiry. A
     * balance only moves when we spend, and an expiry would only mean the next
     * person to open the page pays for the call in page load time, which is
     * the thing being avoided.
     *
     * Null when there was nobody to ask.
     */
    public function checkBalance(): ?BalanceReading
    {
        if (! $this->canReportBalance()) {
            return null;
        }

        $reading = $this->readBalance();

        Cache::forever($this->balanceCacheKey(), $reading->toArray());

        return $reading;
    }

    private function readBalance(): BalanceReading
    {
        try {
            return BalanceReading::of($this->driver()->balance($this));
        } catch (Throwable $e) {
            /*
             * Caught rather than thrown. Every provider with a key is asked by
             * one press of the button, and one of them being down should cost
             * that row its figure rather than the whole answer.
             *
             * It is not counted as a failure against the provider either:
             * disableBecause() exists for a provider failing at the work, and
             * switching one out of the waterfall because its billing endpoint
             * was briefly unreachable would stop lookups that were working
             * perfectly well.
             */
            return BalanceReading::failed($e->getMessage());
        }
    }

    /** Forget what was stored, so the row reads as never asked. */
    public function forgetBalance(): void
    {
        Cache::forget($this->balanceCacheKey());
    }

    private function balanceCacheKey(): string
    {
        return "enrichment.balance.{$this->id}";
    }

    /**
     * Make sure every provider the system knows about has a row.
     *
     * Providers are not invented, they are chosen from the ones we have written
     * code for. So they all appear by themselves and sit inert until somebody
     * puts a key against one. Nothing to create, and no way to create one that
     * points nowhere.
     *
     * Idempotent, and it only ever adds: a row that exists keeps its key, its
     * place in the order and whether it is switched on.
     */
    public static function syncWithDrivers(): void
    {
        // Only on the very first run. A fresh system should start in a
        // defensible order rather than the order the drivers happen to be
        // listed in, and after that the order is whatever somebody chose.
        $wasEmpty = self::query()->doesntExist();

        foreach (self::driversByKind() as $driver => $kind) {
            self::query()->firstOrCreate(
                ['driver' => $driver],
                [
                    'name' => self::labelFor($driver),
                    'kind' => $kind,
                    // Off until it has a key. Nothing goes live on its own.
                    'enabled' => false,
                    'position' => self::query()->where('kind', $kind)->max('position') + 1,
                ],
            );
        }

        if ($wasEmpty) {
            self::orderByPrice();
        }
    }

    /**
     * Where this provider sits in its own half of the waterfall: first, second.
     *
     * Not the stored position, which is global across both steps and full of
     * gaps: the verifiers currently sit at 1, 4, 5 and 6. Those numbers order
     * correctly and read as nonsense, so the rank is counted rather than shown.
     */
    public function rankInWaterfall(): int
    {
        return 1 + self::query()
            ->where('kind', $this->kind)
            ->where(fn (Builder $q) => $q
                ->where('position', '<', $this->position)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('position', $this->position)
                    ->where('id', '<', $this->id)))
            ->count();
    }

    /**
     * Put every provider back in order of what it costs, cheapest first.
     *
     * The chain stops at the first straight answer, so whoever is asked first
     * settles most leads and nearly all the money goes through that one slot.
     * Ordering by price is therefore the sensible starting point, and the only
     * one that can be argued for without knowing how well each performs.
     *
     * It is a starting point rather than an answer. A dear provider that
     * answers every time can be cheaper per address than a cheap one that
     * rarely does, which is what the performance page exists to reveal. Once it
     * has real traffic to report on, its numbers beat this rule.
     */
    public static function orderByPrice(): void
    {
        foreach ([self::KIND_FIND => 1, self::KIND_VERIFY => 1000] as $kind => $from) {
            self::query()
                ->where('kind', $kind)
                ->get()
                // One composite key rather than two sort callbacks: the cost is
                // zero-padded so it compares as a number would, with the name
                // settling ties so the result is stable rather than incidental.
                ->sortBy(fn (self $provider): string => sprintf(
                    '%020.6f|%s',
                    $provider->costPerLookup(),
                    $provider->name,
                ))
                ->values()
                ->each(function (self $provider, int $index) use ($from): void {
                    $provider->forceFill(['position' => $from + $index])->saveQuietly();
                });
        }
    }

    /**
     * Renumber so the two halves never share a range.
     *
     * Reordering sorts by position alone, and the two chains started out
     * interleaved: the finders sat at 2 and 6 while the verifiers had 1, 3, 4
     * and 5. Sorted by that, the reorder screen dealt them out alternately and
     * the two independent sequences looked like one muddled list.
     *
     * Finders take 1 upwards and verifiers 1000 upwards, so any sort by
     * position keeps each half whole and in order.
     */
    public static function renumber(): void
    {
        foreach ([self::KIND_FIND => 1, self::KIND_VERIFY => 1000] as $kind => $from) {
            self::query()
                ->where('kind', $kind)
                ->inPositionOrder()
                ->get()
                ->each(function (self $provider, int $index) use ($from): void {
                    $provider->forceFill(['position' => $from + $index])->saveQuietly();
                });
        }
    }

    /**
     * The providers that will actually be asked for this step, in order.
     *
     * Skips anything switched off or without a key, so the chain describes what
     * will happen rather than what is configured.
     *
     * @return Collection<int, self>
     */
    public static function chainFor(string $kind): Collection
    {
        return self::query()
            ->inWaterfall($kind)
            ->get()
            ->filter->isReady()
            ->values();
    }

    /**
     * What to call this provider on screen.
     *
     * Asked of the driver class, which is the thing that knows, so adding a
     * provider is a class and one line of config rather than two lines that
     * have to agree. A driver with no label - a test double, say - is called
     * by its key.
     */
    private static function labelFor(string $driver): string
    {
        $class = config("enrichment.drivers.{$driver}");

        return is_string($class) && method_exists($class, 'label') ? $class::label() : $driver;
    }

    /** Whether this provider has what it needs to be called at all. */
    public function isConfigured(): bool
    {
        return filled($this->credential('api_key'));
    }

    /**
     * Switched on and able to answer.
     *
     * A provider with no key would fail every call and eventually switch itself
     * off for failing, which reads as a broken provider rather than an empty
     * field. It is skipped instead.
     */
    public function isReady(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /**
     * Switch a provider off because it is failing, rather than because someone
     * chose to.
     *
     * A provider out of credit, or refusing every request, has to stop being
     * tried: otherwise every lead behind it fails on the way to one that would
     * have worked. The reason is recorded so this is distinguishable from a
     * deliberate decision when somebody looks later.
     */
    public function disableBecause(string $reason): void
    {
        $this->forceFill([
            'enabled' => false,
            'disabled_reason' => mb_substr($reason, 0, 255),
            'disabled_at' => now(),
        ])->save();
    }
}
