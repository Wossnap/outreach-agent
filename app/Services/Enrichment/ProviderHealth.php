<?php

namespace App\Services\Enrichment;

use App\Models\ActivityLog;
use App\Models\EnrichmentProvider;
use Illuminate\Support\Collection;

/**
 * Whether the finders and verifiers can actually do any work, and what to tell
 * somebody when they cannot.
 *
 * "Switched on" is not the same as "usable". A provider with an empty account
 * is switched on right up until five of its calls have failed, and every lead
 * that reaches it in the meantime is a failed call. So the retry asks the
 * provider what it has left before sending anybody its way, and a provider
 * that the waterfall switched off comes back once the account is topped up.
 *
 * Only a provider the waterfall switched off comes back by itself. That is the
 * one with a reason recorded against it. Somebody who switched one off by hand
 * meant it, and the switch clears the reason.
 */
class ProviderHealth
{
    /**
     * Ask every provider with a key what is left, and keep the answers.
     *
     * Free: a balance endpoint costs nothing to call. Kept in the same cache
     * the Email waterfall page reads, so the page and the warnings agree.
     */
    public function refreshBalances(): void
    {
        EnrichmentProvider::query()->get()
            ->filter->canReportBalance()
            ->each->checkBalance();
    }

    /**
     * The switched-on providers of one kind that have credit to spend.
     *
     * Read from the stored balances, so call refreshBalances() first when the
     * answer has to be current. A provider that cannot report a balance is
     * given the benefit of the doubt: nothing says it is empty.
     *
     * @return Collection<int, EnrichmentProvider>
     */
    public function usable(string $kind): Collection
    {
        return EnrichmentProvider::chainFor($kind)
            ->filter(fn (EnrichmentProvider $provider): bool => $this->hasCredit($provider))
            ->values();
    }

    /**
     * Switch back on every provider the waterfall switched off, once its
     * account has credit again. Reads the stored balances, so call
     * refreshBalances() first.
     *
     * @return Collection<int, EnrichmentProvider> the ones switched back on
     */
    public function reviveTopUps(): Collection
    {
        $revived = EnrichmentProvider::query()
            ->where('enabled', false)
            ->whereNotNull('disabled_reason')
            ->get()
            // Only on a reading that says so. "Nobody asked yet" is good enough
            // to keep using a provider, not to switch a failing one back on.
            ->filter(function (EnrichmentProvider $provider): bool {
                $reading = $provider->cachedBalance();

                return $reading !== null && $reading->succeeded() && ! $reading->balance->isEmpty();
            });

        foreach ($revived as $provider) {
            // Saving forgets the stored balance; the one just read is still
            // true, so it is put back rather than shown as never checked.
            $reading = $provider->cachedBalance();

            $provider->forceFill([
                'enabled' => true,
                'disabled_reason' => null,
                'disabled_at' => null,
            ])->save();

            $provider->rememberBalance($reading);

            ActivityLog::record(
                event: 'provider_switched_back_on',
                message: "{$provider->name} has credit again and was switched back on.",
                subject: $provider,
            );
        }

        return $revived->values();
    }

    /**
     * Everything somebody should know about, keyed so that each distinct
     * problem is only ever reported once while it lasts.
     *
     * Nothing is reported while lookups are switched off as a whole: that was
     * a decision, not a failure.
     *
     * @return array<string, string> key => sentence
     */
    public function alerts(): array
    {
        if (EnrichmentSwitch::isOff()) {
            return [];
        }

        $alerts = [];

        if (EnrichmentProvider::chainFor(EnrichmentProvider::KIND_FIND)->isEmpty()) {
            $alerts['none:find'] = 'No email finder is switched on. Leads without an address will wait to retry until one is.';
        }

        if (EnrichmentProvider::chainFor(EnrichmentProvider::KIND_VERIFY)->isEmpty()) {
            $alerts['none:verify'] = 'No email checker is switched on. Addresses that are found will wait to retry until one is.';
        }

        $low = (int) config('enrichment.alerts.low_credits');

        foreach (EnrichmentProvider::query()->inPositionOrder()->get() as $provider) {
            if (! $provider->enabled && filled($provider->disabled_reason)) {
                $alerts["off:{$provider->id}"] = "{$provider->name} was switched off: {$provider->disabled_reason}";

                continue;
            }

            if (! $provider->isReady()) {
                continue;
            }

            $remaining = $provider->cachedBalance()?->balance?->remaining;

            if ($remaining === null) {
                continue;
            }

            if ($remaining <= 0) {
                $alerts["empty:{$provider->id}"] = "{$provider->name} has no credits left.";
            } elseif ($remaining < $low) {
                $alerts["low:{$provider->id}"] = "{$provider->name} is running low: {$remaining} credits left.";
            }
        }

        return $alerts;
    }

    private function hasCredit(EnrichmentProvider $provider): bool
    {
        if (! $provider->canReportBalance()) {
            return true;
        }

        $reading = $provider->cachedBalance();

        /*
         * Nobody has asked yet: no reason to think it is empty. A reading that
         * failed is different. The balance endpoint answering with an error is
         * the same key and the same account the lookups would use, so it is
         * treated the way a failed lookup is.
         */
        if ($reading === null) {
            return true;
        }

        return $reading->succeeded() && ! $reading->balance->isEmpty();
    }
}
