<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for leads the old waterfall left in the wrong status.
 *
 * - "Not found" or "risky" given because a finder or checker failed, not
 *   because it answered: a call of that kind failed in the few minutes before
 *   the lead was marked, which is as long as one lookup can run.
 * - Stuck at "finding" or "verifying" long after any lookup would have ended,
 *   with nothing running for it.
 *
 * Both go to waiting to retry, where the retry picks them up; any address
 * already found stays on the lead. Reports what it would change and changes
 * nothing unless run with --apply.
 */
class RepairLookupStatuses extends Command
{
    protected $signature = 'enrichment:repair-statuses {--apply : Make the changes; without it, only report them}';

    protected $description = 'Put leads wrongly settled by a failed lookup, or stuck mid-lookup, back to waiting to retry';

    public function handle(): int
    {
        $groups = [
            'Marked "not found" by a finder that failed' => $this->settledByFailure(Contact::EMAIL_NOT_FOUND, 'find'),
            'Marked "risky" by a checker that failed' => $this->settledByFailure(Contact::EMAIL_RISKY, 'verify'),
            'Stuck at "finding" or "verifying"' => fn () => DB::table('contacts')
                ->whereIn('email_status', [Contact::EMAIL_FINDING, Contact::EMAIL_VERIFYING])
                ->where('updated_at', '<', now()->subMinutes((int) config('enrichment.retry.stale_minutes'))),
        ];

        $apply = (bool) $this->option('apply');
        $total = 0;

        foreach ($groups as $label => $query) {
            $count = $query()->count();
            $total += $count;
            $this->line(sprintf('%-45s %d', $label.':', $count));

            if ($apply && $count > 0) {
                $query()->update(['email_status' => Contact::EMAIL_WAITING, 'email_checked_at' => null, 'updated_at' => now()]);
            }
        }

        $this->info($apply
            ? "{$total} lead(s) moved to waiting to retry."
            : "{$total} lead(s) would move to waiting to retry. Nothing changed: run again with --apply.");

        return self::SUCCESS;
    }

    /** Leads settled as $status where a $kind call failed in the run that settled them. */
    private function settledByFailure(string $status, string $kind): \Closure
    {
        return fn () => DB::table('contacts')
            ->where('email_status', $status)
            ->whereNotNull('email_checked_at')
            ->whereExists(fn (Builder $lookups) => $lookups
                ->select(DB::raw(1))
                ->from('email_lookups')
                ->whereColumn('email_lookups.contact_id', 'contacts.id')
                ->where('email_lookups.kind', $kind)
                ->where('email_lookups.result', 'error')
                ->whereColumn('email_lookups.created_at', '<=', 'contacts.email_checked_at')
                ->whereRaw("email_lookups.created_at >= contacts.email_checked_at - interval '10 minutes'"));
    }
}
