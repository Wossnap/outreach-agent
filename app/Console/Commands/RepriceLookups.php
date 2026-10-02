<?php

namespace App\Console\Commands;

use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: puts every past lookup at the corrected published prices.
 *
 * A call's cost is stored when the call is made, so correcting a price only
 * changes new calls, and Spend kept adding up the old figures (Hunter at
 * $0.098 a find against a published $0.0245). This reprices the history by the
 * rule a new call is charged by: a finder pays for an address returned, a
 * verifier for a verdict it committed to, anything less only where the
 * provider bills a miss, and a failed call never.
 *
 * Reports the totals before and after and changes nothing unless run with
 * --apply.
 */
class RepriceLookups extends Command
{
    protected $signature = 'enrichment:reprice-lookups {--apply : Make the changes; without it, only report them}';

    protected $description = 'Recalculate the cost of past lookups at the current published prices';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        foreach (array_keys(config('enrichment.drivers', [])) as $driver) {
            $price = EnrichmentProvider::listPriceForDriver($driver);

            if ($price === null) {
                continue;
            }

            $answered = EnrichmentProvider::kindForDriver($driver) === EnrichmentProvider::KIND_FIND
                ? [EmailLookup::RESULT_FOUND]
                : [EmailLookup::RESULT_VALID, EmailLookup::RESULT_INVALID];

            $lookups = fn () => DB::table('email_lookups')->where('driver', $driver);
            $hits = $lookups()->whereIn('result', $answered)->count();
            $misses = $lookups()->whereNotIn('result', [...$answered, EmailLookup::RESULT_ERROR])->count();
            $now = (float) $lookups()->sum('cost');
            $missCost = $price->billedOnMiss ? $price->perLookup : 0;
            $after = $hits * $price->perLookup + $misses * $missCost;

            $rows[] = [$driver, $lookups()->count(), sprintf('$%.2f', $now), sprintf('$%.2f', $after)];

            if ($apply) {
                $lookups()->whereIn('result', $answered)->update(['cost' => $price->perLookup]);
                $lookups()->whereNotIn('result', [...$answered, EmailLookup::RESULT_ERROR])->update(['cost' => $missCost]);
                $lookups()->where('result', EmailLookup::RESULT_ERROR)->update(['cost' => 0]);
            }
        }

        $this->table(['Provider', 'Calls', 'Stored now', 'At current price'], $rows);
        $this->info($apply ? 'Past lookups repriced.' : 'Nothing changed: run again with --apply.');

        return self::SUCCESS;
    }
}
