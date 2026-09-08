<?php

namespace App\Livewire\Settings;

use App\Models\EmailLookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What each provider cost, and how often it was any good.
 *
 * The figure that matters is the last column: cost per answer, not cost per
 * call. A provider charging a fifth as much per call but answering one time in
 * ten costs twice as much per address found as the dearer one that answers
 * every time, and nothing but this page will ever show that.
 *
 * Misses and failures are counted for the same reason. A chain judged only on
 * its hits looks perfect and tells you nothing.
 */
#[Layout('layouts.app')]
class WaterfallPerformance extends Component
{
    public function render()
    {
        $hits = collect([
            EmailLookup::RESULT_FOUND,
            EmailLookup::RESULT_VALID,
            EmailLookup::RESULT_INVALID,
        ])->map(fn (string $r): string => "'{$r}'")->implode(', ');

        $rows = EmailLookup::query()
            // Only what somebody actually sells. The free syntax and DNS check
            // and a bounce are recorded as lookups so they show on a lead's
            // history, but neither was bought: listed here they appeared as
            // providers with a perfect answer rate and a cost of nothing.
            ->whereNotIn('driver', EmailLookup::pseudoDrivers())
            ->selectRaw('provider_name, driver, kind')
            ->selectRaw('COUNT(*) as calls')
            // A verdict either way is worth paying for: being told an address
            // is dead is exactly what stops the send.
            ->selectRaw("SUM(CASE WHEN result IN ({$hits}) THEN 1 ELSE 0 END) as answers")
            ->selectRaw('SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as failures', [EmailLookup::RESULT_ERROR])
            ->selectRaw('SUM(cost) as spent')
            ->selectRaw('AVG(duration_ms) as avg_ms')
            ->groupBy('provider_name', 'driver', 'kind')
            ->orderByRaw('SUM(cost) DESC')
            ->get();

        return view('livewire.settings.waterfall-performance', [
            'rows' => $rows,
            'spent' => (float) DB::table('email_lookups')->sum('cost'),
            'bouncedByFinder' => $this->bouncedByFinder(),
            'rejectedFree' => EmailLookup::query()->where('driver', EmailLookup::DRIVER_FREE_GATE)->count(),
        ]);
    }

    /**
     * How many addresses each finder supplied that then failed to deliver.
     *
     * The number the whole merge was for. A hit rate says how often a provider
     * answers; this says how often it was right, which is the only thing a
     * bounce can tell us and the one thing neither system could see alone.
     *
     * Read through the contact rather than out of the bounce row's JSON, which
     * also holds it: this is an ordinary join that means the same thing on
     * every database, and email_provider is precisely "who gave us this
     * address".
     *
     * @return Collection<string, int>
     */
    private function bouncedByFinder(): Collection
    {
        return EmailLookup::query()
            ->join('contacts', 'contacts.id', '=', 'email_lookups.contact_id')
            ->where('email_lookups.driver', EmailLookup::DRIVER_BOUNCE)
            ->whereNotNull('contacts.email_provider')
            ->groupBy('contacts.email_provider')
            // Both columns aliased, and plucked by the alias: Postgres names
            // an unaliased COUNT(*) column its own way, and plucking the raw
            // expression throws.
            ->selectRaw('contacts.email_provider as finder, COUNT(*) as bounced')
            ->pluck('bounced', 'finder');
    }
}
