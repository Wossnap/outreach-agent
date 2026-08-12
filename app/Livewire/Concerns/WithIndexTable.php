<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * House index-table standard: server-side sorting persisted in the URL,
 * page reset on filter changes, and a collapsible filter panel. Components
 * define their own #[Url] filter properties plus:
 *   - array $sortable  (allow-listed sort columns)
 *   - string $defaultSort / $defaultDirection
 *   - filterProperties(): array of property names counted as "active filters"
 */
trait WithIndexTable
{
    #[Url(as: 'sort')]
    public string $sortField = '';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

    public bool $showFilters = false;

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortable, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }

    public function clearFilters(): void
    {
        foreach ($this->filterProperties() as $property) {
            $this->reset($property);
        }

        $this->resetPage();
    }

    public function activeFilterCount(): int
    {
        return collect($this->filterProperties())
            ->filter(fn (string $property) => filled($this->{$property}))
            ->count();
    }

    protected function applySort(Builder $query): Builder
    {
        $field = in_array($this->sortField, $this->sortable, true) ? $this->sortField : $this->defaultSort;
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($field, $direction);
    }

    /**
     * Reset pagination whenever any filter property changes.
     */
    public function updated(string $property): void
    {
        if (in_array(explode('.', $property)[0], $this->filterProperties(), true)) {
            $this->resetPage();
        }
    }
}
