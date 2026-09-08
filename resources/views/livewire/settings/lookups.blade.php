<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    Lookups <span class="text-sm font-normal text-gray-500 dark:text-gray-300">{{ $lookups->total() }}</span>
                </h2>
                <button wire:click="toggleFilters"
                    class="px-3 py-2 border dark:border-gray-700 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Filters
                    @if ($this->activeFilterCount() > 0)
                        <span class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-indigo-600 text-white">{{ $this->activeFilterCount() }}</span>
                    @endif
                </button>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Every call made to a provider, including the ones that found nothing and the ones that
                    failed. <a href="{{ route('settings.waterfall-performance') }}" class="underline" wire:navigate>Spend</a>
                    is this same list added up; this is where a verdict can be argued with, because it keeps
                    what the provider said in its own words.
                </p>
            </div>

            @if ($showFilters)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 grid sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <x-input-label value="Provider" />
                        <div class="mt-1 space-y-1 max-h-40 overflow-y-auto">
                            @forelse ($availableProviders as $provider)
                                <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model.live="providers" value="{{ $provider }}" class="rounded border-gray-300 dark:border-gray-600"> {{ $provider }}
                                </label>
                            @empty
                                <p class="text-gray-500 dark:text-gray-400 text-xs">Nothing has been looked up yet</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Said" />
                        <div class="mt-1 space-y-1 max-h-40 overflow-y-auto">
                            @foreach ($availableResults as $value => $label)
                                <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model.live="results" value="{{ $value }}" class="rounded border-gray-300 dark:border-gray-600"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div>
                            <x-input-label value="Step" />
                            <select wire:model.live="kind" class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md text-sm">
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
                        <button wire:click="clearFilters" class="text-sm text-indigo-600 hover:underline">Clear all</button>
                    </div>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-300">
                        <tr>
                            <x-index.sort-header field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">When</x-index.sort-header>
                            <x-index.sort-header field="provider_name" :sort-field="$sortField" :sort-direction="$sortDirection">Provider</x-index.sort-header>
                            <x-index.sort-header field="result" :sort-field="$sortField" :sort-direction="$sortDirection">Said</x-index.sort-header>
                            <th class="px-6 py-3 font-medium">Lead</th>
                            <th class="px-6 py-3 font-medium">Because</th>
                            <x-index.sort-header field="cost" :sort-field="$sortField" :sort-direction="$sortDirection">Cost</x-index.sort-header>
                            <x-index.sort-header field="duration_ms" :sort-field="$sortField" :sort-direction="$sortDirection">Took</x-index.sort-header>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @forelse ($lookups as $lookup)
                            <tr wire:key="lookup-{{ $lookup->id }}">
                                <td class="px-6 py-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-300">
                                    {{ $lookup->created_at->timezone(config('outreach.timezone'))->format('j M, H:i:s') }}
                                </td>
                                <td class="px-6 py-3">
                                    <span class="font-medium">{{ $lookup->provider_name }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-300">
                                        {{ $lookup->kind === \App\Models\EnrichmentProvider::KIND_FIND ? 'looking for an address' : 'checking an address' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap
                                        @class([
                                            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => in_array($lookup->result, [\App\Models\EmailLookup::RESULT_FOUND, \App\Models\EmailLookup::RESULT_VALID], true),
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $lookup->result === \App\Models\EmailLookup::RESULT_CATCH_ALL,
                                            'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' => in_array($lookup->result, [\App\Models\EmailLookup::RESULT_INVALID, \App\Models\EmailLookup::RESULT_ERROR], true),
                                            'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-300' => in_array($lookup->result, [\App\Models\EmailLookup::RESULT_NOTHING, \App\Models\EmailLookup::RESULT_UNKNOWN], true),
                                        ])">
                                        {{ $lookup->resultLabel() }}
                                    </span>
                                </td>
                                <td class="px-6 py-3">
                                    @if ($lookup->contact)
                                        {{-- A finder is called because there is no address, so a
                                             name is often all there is to show. --}}
                                        <a href="{{ route('contacts.index', ['email' => $lookup->contact->email, 'name' => $lookup->contact->email ? '' : $lookup->contact->name]) }}"
                                            class="text-indigo-600 dark:text-indigo-400 hover:underline" wire:navigate>
                                            {{ $lookup->contact->email ?: ($lookup->contact->name ?: 'lead #'.$lookup->contact_id) }}
                                        </a>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">lead removed</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-xs text-gray-600 dark:text-gray-300 max-w-xs truncate" title="{{ $lookup->explain() }}">
                                    {{ $lookup->explain() ?? '—' }}
                                </td>
                                <td class="px-6 py-3 text-right font-mono text-xs whitespace-nowrap">
                                    {{ (float) $lookup->cost === 0.0 ? 'free' : '$'.number_format((float) $lookup->cost, 6) }}
                                </td>
                                <td class="px-6 py-3 text-right text-xs text-gray-500 dark:text-gray-300 whitespace-nowrap">
                                    {{ $lookup->duration_ms === null ? '—' : number_format($lookup->duration_ms).'ms' }}
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <button wire:click="toggleExpand({{ $lookup->id }})" class="text-indigo-600 hover:underline text-xs">
                                        {{ $expandedId === $lookup->id ? 'Hide' : 'Detail' }}
                                    </button>
                                </td>
                            </tr>

                            @if ($expandedId === $lookup->id)
                                <tr wire:key="lookup-detail-{{ $lookup->id }}">
                                    <td colspan="8" class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40">
                                        <p class="text-sm font-semibold mb-2">What the provider said</p>
                                        {{-- Their words, not ours. The verdict in the Said column is
                                             our translation of this, and this is what settles an
                                             argument about the translation. --}}
                                        @if ($lookup->detailPairs() === [])
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                Nothing was recorded for this call. Some providers say nothing at all when
                                                they have no answer.
                                            </p>
                                        @else
                                            <dl class="grid sm:grid-cols-2 gap-x-8 gap-y-1 text-sm">
                                                @foreach ($lookup->detailPairs() as $label => $value)
                                                    <div class="flex gap-2">
                                                        <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                                        <dd class="font-mono text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
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
                                <td colspan="8" class="px-6 py-10 text-center text-gray-500 dark:text-gray-300">
                                    @if ($this->activeFilterCount() > 0)
                                        No calls match these filters. <button wire:click="clearFilters" class="text-indigo-600 hover:underline">Clear all</button>
                                    @else
                                        Nothing has been looked up yet. Add a provider key on the
                                        <a href="{{ route('settings.waterfall') }}" class="text-indigo-600 hover:underline" wire:navigate>Email waterfall</a> page.
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
