<?php

namespace App\Console\Commands;

use App\Jobs\EnrichContact;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Models\Suppression;
use App\Services\Enrichment\EnrichmentSwitch;
use App\Services\Enrichment\ProviderHealth;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sends leads that are still waiting through the waterfall again.
 *
 * A lead waits when there was nobody able to answer for it: every finder was
 * out of credits, or an address was found while every checker was. Nothing
 * else ever looks at it again, so without this it waits for good.
 *
 * Asks first, sends second. The providers are asked what they have left before
 * anybody is queued, and a run with nobody able to take the work does nothing
 * at all, rather than walking a batch of leads into calls that will fail. A
 * run with nobody waiting asks no provider anything.
 *
 * A provider the waterfall switched off for failing, and that has credit
 * again, is switched back on here, from the balance this run has just read.
 * Leaving that to the hourly check would leave a topped-up provider idle for
 * up to an hour while every retry in between found nobody to send work to.
 *
 * Providers that already answered for a lead are not asked again (see
 * EmailWaterfall::run), so a retry only ever pays for answers it does not
 * have.
 */
class RetryLookups extends Command
{
    protected $signature = 'enrichment:retry';

    protected $description = 'Send leads still waiting for an address or a check through the waterfall again';

    public function handle(ProviderHealth $health): int
    {
        if (EnrichmentSwitch::isOff()) {
            $this->info('Lookups are switched off. Nothing retried.');

            return self::SUCCESS;
        }

        // Nobody waiting, nothing to ask: not even a free balance call.
        if (! $this->waiting()->exists()) {
            $this->info('Nobody is waiting. Nothing retried.');

            return self::SUCCESS;
        }

        $health->refreshBalances();

        foreach ($health->reviveTopUps() as $provider) {
            $this->info("{$provider->name} has credit again and was switched back on.");
        }

        $canFind = $health->usable(EnrichmentProvider::KIND_FIND)->isNotEmpty();
        $canCheck = $health->usable(EnrichmentProvider::KIND_VERIFY)->isNotEmpty();

        /*
         * Each lead needs only the half it is missing. One with no address
         * needs a finder; one holding an address needs a checker. So a run
         * with finders but no checkers still finds addresses, which are kept
         * and checked later, and one with checkers but no finders still clears
         * the addresses waiting on a check.
         */
        if (! $canFind && ! $canCheck) {
            $this->info('No finder or checker has credit to spend. Nothing retried.');

            return self::SUCCESS;
        }

        $leads = $this->waiting()
            ->where(fn (Builder $query) => $query
                ->when($canFind, fn (Builder $q) => $q->orWhereNull('email'))
                ->when($canCheck, fn (Builder $q) => $q->orWhereNotNull('email')))
            ->orderBy('id')
            ->limit((int) config('enrichment.retry.batch'))
            ->get();

        $spacing = (int) config('enrichment.retry.spacing_seconds');
        $queued = 0;

        foreach ($leads as $lead) {
            EnrichContact::dispatch($lead->id)->delay(now()->addSeconds($spacing * $queued));
            $queued++;
        }

        if ($queued > 0) {
            ActivityLog::record(
                event: 'lookups_retried',
                message: "{$queued} waiting lead(s) sent through the email waterfall again.",
                context: ['queued' => $queued, 'finders' => $canFind, 'checkers' => $canCheck],
            );
        }

        $this->info("{$queued} lead(s) queued.");

        return self::SUCCESS;
    }

    /**
     * Leads to send through again.
     *
     * Every lead waiting to retry, however recently it got there: the
     * waterfall put it there because it could not finish, so nothing of its
     * own is still queued. And a lead still pending, finding or verifying long
     * after any lookup would have finished, whose lookup never ran or died part
     * way, which only the time it has sat there can show.
     */
    private function waiting(): Builder
    {
        return Contact::query()
            ->where(fn (Builder $query) => $query
                ->where('email_status', Contact::EMAIL_WAITING)
                ->orWhere(fn (Builder $stale) => $stale
                    ->whereIn('email_status', [Contact::EMAIL_PENDING, Contact::EMAIL_FINDING, Contact::EMAIL_VERIFYING])
                    ->where('updated_at', '<', now()->subMinutes((int) config('enrichment.retry.stale_minutes')))))
            /*
             * Paying to find or check somebody who has opted out buys an answer
             * that could never be used. Left out here rather than skipped in
             * the loop, so a pile of them cannot fill every batch and hold
             * everybody else back. Somebody with no address cannot be on the
             * list, and NOT IN is never true for a null, so they are asked for
             * separately.
             */
            ->where(fn (Builder $query) => $query
                ->whereNull('email')
                ->orWhereNotIn('email', Suppression::query()->select('email')));
    }
}
