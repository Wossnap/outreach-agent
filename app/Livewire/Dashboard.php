<?php

namespace App\Livewire;

use App\Services\Reporting\OutreachAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the outreach is doing: sent, opened, replied, bounced, and how Gmail
 * rates the sending domains. The window applies to everything but the spam
 * rate, which is whatever Gmail reported most recently.
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    public const WINDOWS = [
        '7' => 'Last 7 days',
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        'all' => 'All time',
    ];

    #[Url]
    public string $window = '30';

    public function updatedWindow(): void
    {
        if (! isset(self::WINDOWS[$this->window])) {
            $this->window = '30';
        }
    }

    public function syncPostmaster(): void
    {
        Artisan::call('postmaster:sync');

        session()->flash('dashboard-status', trim(Artisan::output()));
    }

    /**
     * The start of the window as a UTC instant, or null for all time.
     *
     * Days are counted in the display timezone and start at midnight there,
     * so "last 7 days" means the same thing as it does on a calendar.
     */
    public function since(): ?CarbonImmutable
    {
        if ($this->window === 'all') {
            return null;
        }

        return CarbonImmutable::now(config('outreach.timezone'))
            ->subDays((int) $this->window)
            ->startOfDay()
            ->utc();
    }

    public function render(OutreachAnalytics $analytics)
    {
        $since = $this->since();
        $now = CarbonImmutable::now(config('outreach.timezone'));

        // All time is not a strip anyone can read; the bars show the last 90
        // days while the tiles still count everything.
        $seriesFrom = $since ?? $now->subDays(90)->startOfDay()->utc();

        $domains = $analytics->spamByDomain();

        return view('livewire.dashboard', [
            'totals' => $analytics->totals($since),
            'daily' => $analytics->daily($seriesFrom, $now),
            'byAutomation' => $analytics->byAutomation($since),
            'domains' => $domains,
            'worstSpam' => $analytics->worstSpamDomain($domains),
            'windows' => self::WINDOWS,
        ]);
    }
}
