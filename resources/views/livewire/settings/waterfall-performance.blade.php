<div>
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Spend</h2>

            <div class="bg-surface border border-rule sm:rounded-card p-6">
                <p class="text-sm text-ink-dim">
                    The column that matters is the last one. A provider charging a fifth as much per call but
                    answering one time in ten costs twice as much per address found as the dearer one that
                    answers every time. Spent so far:
                    <span class="font-medium text-ink">${{ number_format($spent, 4) }}</span>.
                    Every figure here is the sum of individual calls, and each provider's name opens
                    <a href="{{ route('settings.lookups') }}" class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink" wire:navigate>the calls behind it</a>.
                </p>
            </div>

            <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-band text-left text-xs font-semibold uppercase tracking-label text-ink-dim">
                        <tr>
                            <th class="px-4 py-3">Provider</th>
                            <th class="px-4 py-3">Step</th>
                            <th class="px-4 py-3 text-right">Calls</th>
                            <th class="px-4 py-3 text-right">Answers</th>
                            <th class="px-4 py-3 text-right">Hit rate</th>
                            <th class="px-4 py-3 text-right">Failures</th>
                            <th class="px-4 py-3 text-right">Spent</th>
                            <th class="px-4 py-3 text-right">Typical</th>
                            <th class="px-4 py-3 text-right">Per answer</th>
                            <th class="px-4 py-3 text-right" title="Addresses this finder supplied that a message then bounced off. The only measure of whether its answers were right.">Bounced</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule text-ink">
                        @forelse ($rows as $row)
                            @php
                                $calls = (int) $row->calls;
                                $answers = (int) $row->answers;
                                $rate = $calls > 0 ? $answers / $calls : null;
                            @endphp
                            <tr wire:key="row-{{ $row->provider_name }}-{{ $row->kind }}">
                                <td class="px-4 py-3">
                                    {{-- The totals are otherwise a dead end: a hit rate of 40% says
                                         nothing about which leads were missed or what was said
                                         about them, which is what an argument over a verdict
                                         needs. --}}
                                    <a href="{{ route('settings.lookups', ['providers' => [$row->provider_name]]) }}"
                                        class="font-medium text-brand hover:underline" wire:navigate>{{ $row->provider_name }}</a>
                                    <span class="block text-xs text-ink-dim">{{ $row->driver }}</span>
                                </td>
                                <td class="px-4 py-3 text-ink-dim">{{ $row->kind }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($calls) }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($answers) }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($rate === null)
                                        <span class="text-ink-dim">not asked yet</span>
                                    @else
                                        <span class="{{ $rate >= 0.7 ? 'text-ink' : ($rate >= 0.4 ? 'text-warn' : 'text-danger') }}">
                                            {{ number_format($rate * 100, 1) }}%
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right {{ (int) $row->failures > 0 ? 'text-danger' : 'text-ink-dim' }}">
                                    {{ number_format((int) $row->failures) }}
                                </td>
                                <td class="px-4 py-3 text-right">${{ number_format((float) $row->spent, 4) }}</td>
                                <td class="px-4 py-3 text-right text-ink-dim">
                                    {{ $row->avg_ms === null ? '—' : number_format((float) $row->avg_ms).'ms' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ $answers === 0 ? '—' : '$'.number_format((float) $row->spent / $answers, 4) }}
                                </td>
                                @php($bounced = (int) ($bouncedByFinder[$row->provider_name] ?? 0))
                                <td class="px-4 py-3 text-right {{ $bounced > 0 ? 'text-danger' : 'text-ink-dim' }}">
                                    {{-- Only a finder supplies an address, so only a finder can be answerable for one that bounced. --}}
                                    {{ $row->kind === \App\Models\EnrichmentProvider::KIND_FIND ? number_format($bounced) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-ink-dim">
                                    Nothing has been looked up yet. Add a provider key on the Email waterfall page.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- The work that cost nothing, which is not a provider and so has
                 no row above, but is still worth knowing about. --}}
            @if ($rejectedFree > 0)
                <p class="text-sm text-ink-dim">
                    The free syntax and DNS checks rejected
                    <span class="font-medium text-ink">{{ number_format($rejectedFree) }}</span>
                    {{ Str::plural('address', $rejectedFree) }} before anything was paid for.
                </p>
            @endif
        </div>
    </div>
</div>