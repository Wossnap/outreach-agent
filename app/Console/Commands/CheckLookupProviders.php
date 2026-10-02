<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\LookupsNeedAttention;
use App\Services\Enrichment\ProviderHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Keeps an eye on the finders and checkers.
 *
 * Asks each one what it has left, switches back on any the waterfall switched
 * off once its account has been topped up, and emails when something new has
 * gone wrong. Until this ran, a provider running dry was only visible to
 * somebody who happened to open the Email waterfall page, and leads sat at
 * Pending for a week before anybody noticed.
 */
class CheckLookupProviders extends Command
{
    /**
     * Which problems have already been reported.
     *
     * Kept in the database rather than the cache, because clearing the cache
     * on a deploy would otherwise send every standing problem again.
     */
    public const REPORTED = 'enrichment.alerts.reported';

    protected $signature = 'enrichment:check-providers';

    protected $description = 'Refresh finder and checker balances, switch topped-up ones back on, and email about new problems';

    public function handle(ProviderHealth $health): int
    {
        $health->refreshBalances();

        foreach ($health->reviveTopUps() as $provider) {
            $this->info("{$provider->name} has credit again and was switched back on.");
        }

        $alerts = $health->alerts();
        $reported = (array) Setting::get(self::REPORTED, []);

        /*
         * Only something that was not already reported sends an email. A
         * problem that clears is forgotten, so if it comes back it is reported
         * again.
         */
        $new = array_diff(array_keys($alerts), $reported);

        if ($new !== [] && ($to = $this->recipients()) !== []) {
            Notification::route('mail', $to)->notify(new LookupsNeedAttention($alerts));
        }

        Setting::put(self::REPORTED, array_keys($alerts));

        foreach ($alerts as $alert) {
            $this->line($alert);
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function recipients(): array
    {
        $to = config('enrichment.alerts.to');

        return $to !== [] ? $to : User::query()->pluck('email')->filter()->values()->all();
    }
}
