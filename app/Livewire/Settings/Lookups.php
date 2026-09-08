<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithIndexTable;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every call ever made to a provider, one row each.
 *
 * The Spend page is the same data added up, and a total is a dead end: a hit
 * rate of 40% says nothing about which leads were missed or what was said
 * about them, which is exactly what an argument over a verdict needs. This is
 * where that argument is settled, in the provider's own words.
 *
 * Misses are here too, and failures. A chain judged only on the calls that
 * worked looks perfect and tells you nothing.
 */
#[Layout('layouts.app')]
class Lookups extends Component
{
    use WithIndexTable, WithPagination;

    /** @var array<string> */
    public array $sortable = ['created_at', 'provider_name', 'result', 'cost', 'duration_ms'];

    public string $defaultSort = 'created_at';

    /** @var array<string> */
    #[Url]
    public array $providers = [];

    /** @var array<string> */
    #[Url]
    public array $results = [];

    #[Url]
    public string $kind = '';

    #[Url]
    public string $lead = '';

    #[Url]
    public string $createdFrom = '';

    #[Url]
    public string $createdTo = '';

    public ?int $expandedId = null;

    protected function filterProperties(): array
    {
        return ['providers', 'results', 'kind', 'lead', 'createdFrom', 'createdTo'];
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function render()
    {
        $query = EmailLookup::query()
            ->with('contact')
            ->when($this->providers !== [], fn ($q) => $q->whereIn('provider_name', $this->providers))
            ->when($this->results !== [], fn ($q) => $q->whereIn('result', $this->results))
            ->when($this->kind, fn ($q) => $q->where('kind', $this->kind))
            /*
             * Matched on either half of who a lead is, because a lookup exists
             * precisely when one of them is missing: a finder is called because
             * there is no address, so searching this page by address alone
             * would never find the call that produced it.
             */
            ->when($this->lead, fn ($q) => $q->whereHas('contact', fn ($c) => $c
                ->whereRaw('lower(email) like ?', ['%'.mb_strtolower($this->lead).'%'])
                ->orWhereRaw('lower(name) like ?', ['%'.mb_strtolower($this->lead).'%'])))
            ->when($this->createdFrom, fn ($q) => $q->where('created_at', '>=', $this->createdFrom.' 00:00:00'))
            ->when($this->createdTo, fn ($q) => $q->where('created_at', '<=', $this->createdTo.' 23:59:59'));

        return view('livewire.settings.lookups', [
            'lookups' => $this->applySort($query)->paginate(50),
            // Read from the calls themselves rather than from the provider
            // table, so a provider deleted since still lists its history.
            'availableProviders' => EmailLookup::query()
                ->distinct()
                ->orderBy('provider_name')
                ->pluck('provider_name'),
            'availableResults' => EmailLookup::results(),
            'kinds' => EnrichmentProvider::kinds(),
        ]);
    }
}
