<?php

namespace App\Services\Enrichment;

use App\Models\ActivityLog;
use App\Models\EnrichmentProvider;
use App\Models\Setting;
use App\Support\Settings\Toggle;
use Illuminate\Support\Collection;

/**
 * The cheapest setup, as one switch.
 *
 * Hunter finds, and its own check settles most addresses at no extra cost;
 * Reoon checks what Hunter is unsure of; BounceBan settles catch-alls. Every
 * other provider is switched off and locked while this is on, rather than left
 * looking switched on and quietly skipped, which would leave somebody reading
 * the page wondering why a provider that says "in use" never is.
 *
 * Switching it off puts every locked provider back exactly as it was,
 * including one the waterfall had switched off for failing, so the full
 * waterfall comes back as it was set up.
 */
class MinimumCost extends Toggle
{
    public const KEY = 'enrichment.minimum_cost';

    public const DEFAULT = false;

    /** The providers the cheapest setup uses, by driver. */
    public const KEEPS = ['hunter', 'reoon', 'bounceban'];

    /** How each locked provider was set before it was locked, by driver. */
    private const REMEMBERED = 'enrichment.minimum_cost.remembered';

    public static function keeps(EnrichmentProvider $provider): bool
    {
        return in_array($provider->driver, self::KEEPS, true);
    }

    /** Whether this provider is held off by the switch right now. */
    public static function locks(EnrichmentProvider $provider): bool
    {
        return self::isOn() && ! self::keeps($provider);
    }

    /**
     * Lock every provider the cheapest setup does not use.
     *
     * @return Collection<int, EnrichmentProvider> the ones that were switched on and are now off
     */
    public static function switchOn(): Collection
    {
        if (self::isOn()) {
            return collect();
        }

        $others = EnrichmentProvider::query()->whereNotIn('driver', self::KEEPS)->get();

        Setting::put(self::REMEMBERED, $others->mapWithKeys(fn (EnrichmentProvider $provider): array => [
            $provider->driver => [
                'enabled' => $provider->enabled,
                'disabled_reason' => $provider->disabled_reason,
                'disabled_at' => $provider->disabled_at?->toIso8601String(),
            ],
        ])->all());

        $wasOn = $others->filter->enabled->values();

        /*
         * The reason is cleared too. It is what marks a provider the waterfall
         * switched off for failing, and the hourly check switches those back on
         * when they have credit; a locked provider must stay off. The reason
         * is remembered above and comes back with it.
         */
        $others->each(fn (EnrichmentProvider $provider) => $provider->forceFill([
            'enabled' => false,
            'disabled_reason' => null,
            'disabled_at' => null,
        ])->save());

        self::turnOn();

        ActivityLog::record(
            event: 'minimum_cost_on',
            message: 'Minimum cost is on: only Hunter, Reoon and BounceBan are used. '
                .($wasOn->isEmpty() ? 'Nothing else was switched on.' : 'Switched off: '.$wasOn->pluck('name')->join(', ').'.'),
        );

        return $wasOn;
    }

    /**
     * Unlock them, each put back exactly as it was before.
     *
     * @return Collection<int, EnrichmentProvider> the ones switched back on
     */
    public static function switchOff(): Collection
    {
        if (self::isOff()) {
            return collect();
        }

        $remembered = (array) Setting::get(self::REMEMBERED, []);
        $restored = collect();

        foreach (EnrichmentProvider::query()->whereIn('driver', array_keys($remembered))->get() as $provider) {
            $was = $remembered[$provider->driver];

            $provider->forceFill([
                'enabled' => (bool) $was['enabled'],
                'disabled_reason' => $was['disabled_reason'],
                'disabled_at' => $was['disabled_at'],
            ])->save();

            if ($provider->enabled) {
                $restored->push($provider);
            }
        }

        Setting::put(self::REMEMBERED, []);
        self::turnOff();

        ActivityLog::record(
            event: 'minimum_cost_off',
            message: 'Minimum cost is off: every provider is back as it was. '
                .($restored->isEmpty() ? 'None of the others had been switched on.' : 'Switched back on: '.$restored->pluck('name')->join(', ').'.'),
        );

        return $restored;
    }

    /**
     * Providers the cheapest setup needs that are not ready to be used.
     *
     * @return Collection<int, EnrichmentProvider>
     */
    public static function missing(): Collection
    {
        return EnrichmentProvider::query()
            ->whereIn('driver', self::KEEPS)
            ->get()
            ->reject->isReady()
            ->values();
    }
}
