<div>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Email waterfall</h2>

            @if ($flash)
                <div class="rounded-md bg-blue-50 dark:bg-blue-900/30 p-4 text-sm text-blue-800 dark:text-blue-200">
                    {{ $flash }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    A lead with no address is passed to each finder in turn until one answers. The address is
                    then offered to each verifier until one commits. Both chains stop at the first straight
                    answer, so whoever is first settles most leads and nearly all the money goes through that
                    slot.
                </p>

                {{-- Asking is a button, never something the page does by
                     itself: these are live calls to six other companies, and
                     making them on every render meant opening the page waited
                     on the slowest of them. --}}
                <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                    <button wire:click="checkBalances" wire:loading.attr="disabled"
                        class="px-3 py-2 text-sm rounded-md border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="checkBalances">Check credits now</span>
                        <span wire:loading wire:target="checkBalances">Asking every provider…</span>
                    </button>
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        @if ($balancesCheckedAt)
                            Credits below were read
                            {{ $balancesCheckedAt->diffForHumans() }},
                            on {{ $balancesCheckedAt->timezone(config('outreach.timezone'))->format('j M Y') }}.
                            They are not refreshed by opening this page.
                        @else
                            Nobody has asked what is left on these accounts yet.
                        @endif
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                    <button wire:click="toggleEnrichment"
                        class="px-3 py-2 text-sm rounded-md {{ $enrichmentOn ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }} text-white">
                        {{ $enrichmentOn ? 'Turn enrichment off' : 'Turn enrichment on' }}
                    </button>
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        @if ($enrichmentOn)
                            Looking up addresses, and spending money doing it.
                        @else
                            <span class="font-medium text-red-600 dark:text-red-400">Off.</span>
                            Nothing is looked up and nothing is spent. {{ $pending }} leads are waiting.
                        @endif
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    {{-- Changes the rule and nothing else. Nobody already
                         waiting is started by this; that is the separate
                         button below, so the two decisions stay apart. --}}
                    <button wire:click="toggleVerifiedEmail"
                        @if ($verifiedRequired)
                            wire:confirm="Stop requiring a confirmed address?&#10;&#10;From now on, a lead pushed with an address is emailed without anything checking it first. Addresses that bounce cost delivery for everybody else.&#10;&#10;Nobody already waiting is started by this."
                        @endif
                        class="px-3 py-2 text-sm rounded-md border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                        {{ $verifiedRequired ? 'Stop requiring a confirmed address' : 'Require a confirmed address' }}
                    </button>
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        @if ($verifiedRequired)
                            Nothing is emailed until an address has been confirmed. Leads wait in
                            <span class="font-mono text-xs">waiting_email</span> until then.
                        @else
                            Leads pushed from now on are emailed on the address supplied, with nothing having checked it.
                        @endif
                    </span>
                </div>

                {{-- The backlog, as a decision of its own. Shown only when
                     there is one to make: the rule is relaxed and people are
                     still queued under the old one. --}}
                @if (! $verifiedRequired && $wouldStart > 0)
                    <div class="flex flex-wrap items-center gap-3 p-3 rounded-md bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                        <button wire:click="startEveryoneWaiting"
                            wire:confirm="Start {{ $wouldStart }} waiting enrollment(s)?&#10;&#10;Each one drafts its first email straight away, to an address nothing has checked. They have been waiting because a confirmed address used to be required.&#10;&#10;This cannot be undone from here: stopping them afterwards is one enrollment at a time."
                            class="px-3 py-2 text-sm rounded-md bg-amber-600 hover:bg-amber-700 text-white">
                            Start the {{ $wouldStart }} still waiting
                        </button>
                        <span class="text-sm text-amber-800 dark:text-amber-300">
                            {{ $wouldStart }} enrollment(s) were queued while a confirmed address was required, and are
                            still waiting. Starting them is a separate decision from changing the rule.
                        </span>
                    </div>
                @endif
            </div>

            @foreach ($chains as $kind => $chain)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg" wire:key="chain-{{ $kind }}">
                    <div class="px-6 py-4 flex items-center justify-between border-b border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">{{ $chain['label'] }}</h3>
                        @if ($loop->first)
                            <button wire:click="orderByPrice" class="text-xs underline text-gray-500 dark:text-gray-300">Order by price</button>
                        @endif
                    </div>

                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($chain['providers'] as $i => $provider)
                            <div class="p-4" wire:key="provider-{{ $provider->id }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                            <span class="text-gray-500 dark:text-gray-400">{{ $i + 1 }}.</span>
                                            {{ $provider->name }}
                                            @if ($provider->enabled && $provider->isConfigured())
                                                <span class="ml-2 text-xs px-2 py-0.5 rounded bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">in use</span>
                                            @elseif (! $provider->isConfigured())
                                                <span class="ml-2 text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-300">no key</span>
                                            @else
                                                <span class="ml-2 text-xs px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">off</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-300 mt-1">
                                            {{ $provider->lookups_count }} calls
                                            @php($price = \App\Models\EnrichmentProvider::listPriceForDriver($provider->driver))
                                            @if ($price)
                                                · ${{ number_format($price->perLookup, 6) }} per call
                                                · {{ $price->billedOnMiss ? 'billed on a miss' : 'free on a miss' }}
                                            @endif
                                        </p>
                                        @include('partials.provider-credits', ['provider' => $provider])

                                        @if ($provider->disabled_reason)
                                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">
                                                Switched off because: {{ $provider->disabled_reason }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <button wire:click="move({{ $provider->id }}, 'up')" @disabled($loop->first)
                                            class="px-2 py-1 text-xs border rounded border-gray-300 dark:border-gray-600 disabled:opacity-30">&uarr;</button>
                                        <button wire:click="move({{ $provider->id }}, 'down')" @disabled($loop->last)
                                            class="px-2 py-1 text-xs border rounded border-gray-300 dark:border-gray-600 disabled:opacity-30">&darr;</button>
                                        <button wire:click="edit({{ $provider->id }})"
                                            class="px-2 py-1 text-xs border rounded border-gray-300 dark:border-gray-600">
                                            {{ $provider->isConfigured() ? 'Replace key' : 'Add a key' }}
                                        </button>
                                        <button wire:click="toggle({{ $provider->id }})"
                                            class="px-2 py-1 text-xs border rounded border-gray-300 dark:border-gray-600">
                                            {{ $provider->enabled ? 'Switch off' : 'Switch on' }}
                                        </button>
                                    </div>
                                </div>

                                @if ($editing === $provider->id)
                                    <form wire:submit="saveKey" class="mt-3 flex items-end gap-2">
                                        <div class="flex-1">
                                            <x-input-label for="apiKey" value="API key" />
                                            <x-text-input id="apiKey" wire:model="apiKey" type="password" class="mt-1 w-full" autocomplete="off" />
                                            <x-input-error :messages="$errors->get('apiKey')" class="mt-1" />
                                            <p class="text-xs text-gray-500 dark:text-gray-300 mt-1">Stored encrypted, and never shown again.</p>
                                        </div>
                                        <x-primary-button>Save</x-primary-button>
                                        <button type="button" wire:click="cancel" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-300">Cancel</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
