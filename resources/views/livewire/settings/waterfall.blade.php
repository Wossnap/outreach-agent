<div>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Email waterfall</h2>

            @if ($flash)
                <div class="rounded-md bg-band border border-rule p-4 text-sm text-ink">
                    {{ $flash }}
                </div>
            @endif

            <div class="bg-surface border border-rule sm:rounded-card p-6 space-y-4">
                <p class="text-sm text-ink-dim">
                    A lead with no address is passed to each finder in turn until one answers. The address is
                    then offered to each verifier until one commits. Both chains stop at the first straight
                    answer, so whoever is first settles most leads and nearly all the money goes through that
                    slot.
                </p>

                {{-- Asking is a button, never something the page does by
                     itself: these are live calls to six other companies, and
                     making them on every render meant opening the page waited
                     on the slowest of them. --}}
                <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-rule">
                    <button wire:click="checkBalances" wire:loading.attr="disabled"
                        class="px-3 py-2 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim disabled:opacity-50 transition">
                        <span wire:loading.remove wire:target="checkBalances">Check credits now</span>
                        <span wire:loading wire:target="checkBalances">Asking every provider…</span>
                    </button>
                    <span class="text-sm text-ink-dim">
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

                <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-rule">
                    <button wire:click="toggleEnrichment"
                        class="px-3 py-2 rounded-md text-sm font-semibold transition {{ $enrichmentOn ? 'border border-danger text-danger hover:bg-danger/10' : 'bg-brand text-brand-ink hover:bg-brand-hover' }}">
                        {{ $enrichmentOn ? 'Turn enrichment off' : 'Turn enrichment on' }}
                    </button>
                    <span class="text-sm text-ink-dim">
                        @if ($enrichmentOn)
                            Looking up addresses, and spending money doing it.
                        @else
                            <span class="font-medium text-danger">Off.</span>
                            Nothing is looked up and nothing is spent. {{ $pending }} leads are waiting.
                        @endif
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button wire:click="toggleMinimumCost" data-minimum-cost
                        @if (! $minimumCost)
                            wire:confirm="Turn Minimum cost on?&#10;&#10;Only Hunter, Reoon and BounceBan will be used. Every other provider is switched off and locked until Minimum cost is turned off again, when each goes back to how it is now."
                        @endif
                        class="px-3 py-2 rounded-md text-sm font-semibold transition {{ $minimumCost ? 'border border-rule-strong text-ink hover:border-ink-dim' : 'bg-brand text-brand-ink hover:bg-brand-hover' }}">
                        {{ $minimumCost ? 'Turn Minimum cost off' : 'Turn Minimum cost on' }}
                    </button>
                    <span class="text-sm text-ink-dim">
                        @if ($minimumCost)
                            <span class="font-medium text-ink">On.</span>
                            Only Hunter, Reoon and BounceBan are used; the rest are locked off.
                        @else
                            Off. Every provider switched on below is used.
                        @endif
                    </span>
                </div>

                @if ($minimumMissing->isNotEmpty())
                    <x-banner tone="warn">
                        Minimum cost needs {{ $minimumMissing->pluck('name')->join(', ', ' and ') }}, which {{ $minimumMissing->count() === 1 ? 'is' : 'are' }} switched off or {{ $minimumMissing->count() === 1 ? 'has' : 'have' }} no key.
                    </x-banner>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    {{-- Changes the rule and nothing else. Nobody already
                         waiting is started by this; that is the separate
                         button below, so the two decisions stay apart. --}}
                    <button wire:click="toggleVerifiedEmail"
                        @if ($verifiedRequired)
                            wire:confirm="Stop requiring a confirmed address?&#10;&#10;From now on, a lead pushed with an address is emailed without anything checking it first. Addresses that bounce cost delivery for everybody else.&#10;&#10;Nobody already waiting is started by this."
                        @endif
                        class="px-3 py-2 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">
                        {{ $verifiedRequired ? 'Stop requiring a confirmed address' : 'Require a confirmed address' }}
                    </button>
                    <span class="text-sm text-ink-dim">
                        @if ($verifiedRequired)
                            Nothing is emailed until an address has been confirmed. Leads wait in
                            <span class="font-mono text-sm">waiting_email</span> until then.
                        @else
                            Leads pushed from now on are emailed on the address supplied, with nothing having checked it.
                        @endif
                    </span>
                </div>

                {{-- The backlog, as a decision of its own. Shown only when
                     there is one to make: the rule is relaxed and people are
                     still queued under the old one. --}}
                @if (! $verifiedRequired && $wouldStart > 0)
                    <div class="flex flex-wrap items-center gap-3 p-3 rounded-md bg-band border border-warn">
                        <button wire:click="startEveryoneWaiting"
                            wire:confirm="Start {{ $wouldStart }} waiting enrollment(s)?&#10;&#10;Each one drafts its first email straight away, to an address nothing has checked. They have been waiting because a confirmed address used to be required.&#10;&#10;This cannot be undone from here: stopping them afterwards is one enrollment at a time."
                            class="px-3 py-2 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover transition">
                            Start the {{ $wouldStart }} still waiting
                        </button>
                        <span class="text-sm text-ink">
                            {{ $wouldStart }} enrollment(s) were queued while a confirmed address was required, and are
                            still waiting. Starting them is a separate decision from changing the rule.
                        </span>
                    </div>
                @endif
            </div>

            @foreach ($chains as $kind => $chain)
                <div class="bg-surface border border-rule sm:rounded-card" wire:key="chain-{{ $kind }}">
                    <div class="px-6 py-4 flex items-center justify-between border-b border-rule">
                        <h3 class="text-base font-semibold text-ink">{{ $chain['label'] }}</h3>
                        @if ($loop->first)
                            <button wire:click="orderByPrice" class="text-xs font-medium text-brand hover:underline">Order by price</button>
                        @endif
                    </div>

                    <div class="divide-y divide-rule">
                        @foreach ($chain['providers'] as $i => $provider)
                            <div class="p-4" wire:key="provider-{{ $provider->id }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            <span class="text-ink-dim">{{ $i + 1 }}.</span>
                                            {{ $provider->name }}
                                            @if (\App\Services\Enrichment\MinimumCost::locks($provider))
                                                <x-pill tone="neutral" class="ml-2">locked off</x-pill>
                                            @elseif ($provider->enabled && $provider->isConfigured())
                                                <x-pill tone="good" class="ml-2">in use</x-pill>
                                            @elseif (! $provider->isConfigured())
                                                <x-pill tone="neutral" class="ml-2">no key</x-pill>
                                            @else
                                                <x-pill tone="warn" class="ml-2">off</x-pill>
                                            @endif
                                        </p>
                                        <p class="text-xs text-ink-dim mt-1">
                                            {{ $provider->lookups_count }} calls
                                            @php($price = \App\Models\EnrichmentProvider::listPriceForDriver($provider->driver))
                                            @if ($price)
                                                · ${{ number_format($price->perLookup, 6) }} per call
                                                · {{ $price->billedOnMiss ? 'billed on a miss' : 'free on a miss' }}
                                            @endif
                                        </p>
                                        @include('partials.provider-credits', ['provider' => $provider])

                                        @if (\App\Services\Enrichment\MinimumCost::locks($provider))
                                            <p class="text-xs text-ink-dim mt-1">Locked off while Minimum cost is on.</p>
                                        @endif

                                        @if ($provider->disabled_reason)
                                            <p class="text-xs text-danger mt-1">
                                                Switched off because: {{ $provider->disabled_reason }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <button wire:click="move({{ $provider->id }}, 'up')" @disabled($loop->first)
                                            class="px-2 py-1 rounded-md border border-rule-strong text-ink text-xs font-semibold hover:border-ink-dim disabled:opacity-30 transition">&uarr;</button>
                                        <button wire:click="move({{ $provider->id }}, 'down')" @disabled($loop->last)
                                            class="px-2 py-1 rounded-md border border-rule-strong text-ink text-xs font-semibold hover:border-ink-dim disabled:opacity-30 transition">&darr;</button>
                                        <button wire:click="edit({{ $provider->id }})"
                                            class="px-2 py-1 rounded-md border border-rule-strong text-ink text-xs font-semibold hover:border-ink-dim transition">
                                            {{ $provider->isConfigured() ? 'Replace key' : 'Add a key' }}
                                        </button>
                                        <button wire:click="toggle({{ $provider->id }})"
                                            @disabled(\App\Services\Enrichment\MinimumCost::locks($provider))
                                            @if (\App\Services\Enrichment\MinimumCost::locks($provider)) title="Locked off while Minimum cost is on" @endif
                                            class="px-2 py-1 rounded-md border border-rule-strong text-ink text-xs font-semibold hover:border-ink-dim disabled:opacity-30 transition">
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
                                            <p class="text-xs text-ink-dim mt-1">Stored encrypted, and never shown again.</p>
                                        </div>
                                        <x-primary-button>Save</x-primary-button>
                                        <button type="button" wire:click="cancel" class="px-3 py-2 text-sm font-semibold text-ink-dim hover:text-ink transition">Cancel</button>
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