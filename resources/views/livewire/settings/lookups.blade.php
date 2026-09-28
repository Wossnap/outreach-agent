<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">
                    Lookups <span class="text-sm font-sans font-normal text-ink-dim">{{ $lookups->total() }}</span>
                </h2>
                <button wire:click="toggleFilters"
                    class="px-3 py-2 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">
                    Filters
                    @if ($this->activeFilterCount() > 0)
                        <x-pill tone="good" class="ml-1">{{ $this->activeFilterCount() }}</x-pill>
                    @endif
                </button>
            </div>

            <div class="bg-surface border border-rule sm:rounded-card p-6">
                <p class="text-sm text-ink-dim">
                    Every call made to a provider, including the ones that found nothing and the ones that
                    failed. <a href="{{ route('settings.waterfall-performance') }}" class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink" wire:navigate>Spend</a>
                    is this same list added up; this is where a verdict can be argued with, because it keeps
                    what the provider said in its own words.
                </p>
            </div>

            @if ($showFilters)
                <div class="bg-surface border border-rule sm:rounded-card p-6 grid sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <x-input-label value="Provider" />
                        <div class="mt-1 space-y-1 max-h-40 overflow-y-auto">
                            @forelse ($availableProviders as $provider)
                                <label class="flex items-center gap-2 text-ink">
                                    <input type="checkbox" wire:model.live="providers" value="{{ $provider }}" class="rounded border-rule-strong text-brand focus:ring-brand"> {{ $provider }}
                                </label>
                            @empty
                                <p class="text-ink-dim text-xs">Nothing has been looked up yet</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Said" />
                        <div class="mt-1 space-y-1 max-h-40 overflow-y-auto">
                            @foreach ($availableResults as $value => $label)
                                <label class="flex items-center gap-2 text-ink">
                                    <input type="checkbox" wire:model.live="results" value="{{ $value }}" class="rounded border-rule-strong text-brand focus:ring-brand"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div>
                            <x-input-label value="Step" />
                            <select wire:model.live="kind" class="mt-1 w-full rounded-md border-rule-strong bg-surface text-ink focus:border-brand focus:ring-brand text-sm">
                                <option value="">Either</option>
                                @foreach ($kinds as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Lead (name or address)" />
                            <x-text-input wire:model.live.debounce.300ms="lead" class="mt-1 w-full" placeholder="Type to search…" />
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <div>
                            <x-input-label value="From" />
                            <x-text-input type="date" wire:model.live="createdFrom" class="mt-1 w-full" />
                        </div>
                        <div>
                            <x-input-label value="to" />
                            <x-text-input type="date" wire:model.live="createdTo" class="mt-1 w-full" />
                        </div>
                    </div>

                    <div class="sm:col-span-3">
                        <button wire:click="clearFilters" class="text-sm font-medium text-brand hover:underline">Clear all</button>
                    </div>
                </div>
            @endif

            <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="text-left">
                        <tr>
                            <x-index.sort-header field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">When</x-index.sort-header>
                            <x-index.sort-header field="provider_name" :sort-field="$sortField" :sort-direction="$sortDirection">Provider</x-index.sort-header>
                            <x-index.sort-header field="result" :sort-field="$sortField" :sort-direction="$sortDirection">Said</x-index.sort-header>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Because</th>
                            <x-index.sort-header field="cost" :sort-field="$sortField" :sort-direction="$sortDirection">Cost</x-index.sort-header>
                            <x-index.sort-header field="duration_ms" :sort-field="$sortField" :sort-direction="$sortDirection">Took</x-index.sort-header>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule text-ink">
                        @forelse ($lookups as $lookup)
                            <tr wire:key="lookup-{{ $lookup->id }}">
                                <td class="px-6 py-3 whitespace-nowrap text-xs text-ink-dim">
                                    {{ $lookup->created_at->timezone(config('outreach.timezone'))->format('j M, H:i:s') }}
                                </td>
                                <td class="px-6 py-3">
                                    <span class="font-medium">{{ $lookup->provider_name }}</span>
                                    <span class="block text-xs text-ink-dim">
                                        {{ $lookup->kind === \App\Models\EnrichmentProvider::KIND_FIND ? 'looking for an address' : 'checking an address' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3">
                                    @php($tone = match (true) {
                                        in_array($lookup->result, [\App\Models\EmailLookup::RESULT_FOUND, \App\Models\EmailLookup::RESULT_VALID], true) => 'good',
                                        $lookup->result === \App\Models\EmailLookup::RESULT_CATCH_ALL => 'warn',
                                        in_array($lookup->result, [\App\Models\EmailLookup::RESULT_INVALID, \App\Models\EmailLookup::RESULT_ERROR], true) => 'danger',
                                        in_array($lookup->result, [\App\Models\EmailLookup::RESULT_NOTHING, \App\Models\EmailLookup::RESULT_UNKNOWN], true) => 'neutral',
                                        default => 'neutral',
                                    })
                                    <x-pill :tone="$tone">{{ $lookup->resultLabel() }}</x-pill>
                                </td>
                                <td class="px-6 py-3">
                                    @if ($lookup->contact)
                                        {{-- A finder is called because there is no address, so a
                                             name is often all there is to show. --}}
                                        <a href="{{ route('contacts.index', ['email' => $lookup->contact->email, 'name' => $lookup->contact->email ? '' : $lookup->contact->name]) }}"
                                            class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink" wire:navigate>
                                            {{ $lookup->contact->email ?: ($lookup->contact->name ?: 'lead #'.$lookup->contact_id) }}
                                        </a>
                                    @else
                                        <span class="text-ink-dim">lead removed</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-xs text-ink-dim max-w-xs truncate" title="{{ $lookup->explain() }}">
                                    {{ $lookup->explain() ?? '—' }}
                                </td>
                                <td class="px-6 py-3 text-right text-xs whitespace-nowrap">
                                    {{ (float) $lookup->cost === 0.0 ? 'free' : '$'.number_format((float) $lookup->cost, 6) }}
                                </td>
                                <td class="px-6 py-3 text-right text-xs text-ink-dim whitespace-nowrap">
                                    {{ $lookup->duration_ms === null ? '—' : number_format($lookup->duration_ms).'ms' }}
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <button wire:click="toggleExpand({{ $lookup->id }})" class="text-xs font-medium text-brand hover:underline">
                                        {{ $expandedId === $lookup->id ? 'Hide' : 'Detail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ($expandedId === $lookup->id)
                                <tr wire:key="lookup-detail-{{ $lookup->id }}">
                                    <td colspan="8" class="px-6 py-4 bg-band">
                                        <p class="text-sm font-semibold text-ink mb-2">What the provider said</p>
                                        {{-- Their words, not ours. The verdict in the Said column is
                                             our translation of this, and this is what settles an
                                             argument about the translation. --}}
                                        @if ($lookup->detailPairs() === [])
                                            <p class="text-sm text-ink-dim">
                                                Nothing was recorded for this call. Some providers say nothing at all when
                                                they have no answer.
                                            </p>
                                        @else
                                            <dl class="grid sm:grid-cols-2 gap-x-8 gap-y-1 text-sm">
                                                @foreach ($lookup->detailPairs() as $label => $value)
                                                    <div class="flex gap-2">
                                                        <dt class="text-ink-dim">{{ $label }}</dt>
                                                        <dd class="font-mono text-sm text-ink">{{ $value }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                        <p class="mt-3 text-xs text-ink-dim">
                                            Driver <span class="font-mono">{{ $lookup->driver }}</span>
                                            @if ((float) $lookup->cost === 0.0 && $lookup->result === \App\Models\EmailLookup::RESULT_ERROR)
                                                · A call that failed bought nothing, whatever the billing says.
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-10 text-center text-ink-dim">
                                    @if ($this->activeFilterCount() > 0)
                                        No calls match these filters. <button wire:click="clearFilters" class="font-medium text-brand hover:underline">Clear all</button>
                                    @else
                                        Nothing has been looked up yet. Add a provider key on the
                                        <a href="{{ route('settings.waterfall') }}" class="font-medium text-brand hover:underline" wire:navigate>Email waterfall</a> page.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $lookups->links() }}
        </div>
    </div>
</div>