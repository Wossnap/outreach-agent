<div>
    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Spend</h2>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    The column that matters is the last one. A provider charging a fifth as much per call but
                    answering one time in ten costs twice as much per address found as the dearer one that
                    answers every time. Spent so far:
                    <span class="font-mono">${{ number_format($spent, 4) }}</span>.
                    Every figure here is the sum of individual calls, and each provider's name opens
                    <a href="{{ route('settings.lookups') }}" class="underline" wire:navigate>the calls behind it</a>.
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-left text-xs uppercase text-gray-500 dark:text-gray-300">
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
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
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
                                        class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline" wire:navigate>{{ $row->provider_name }}</a>
                                    <span class="block text-xs text-gray-500 dark:text-gray-300">{{ $row->driver }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row->kind }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($calls) }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format($answers) }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($rate === null)
                                        <span class="text-gray-500 dark:text-gray-400">not asked yet</span>
                                    @else
                                        <span class="{{ $rate >= 0.7 ? 'text-green-600 dark:text-green-400' : ($rate >= 0.4 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">
                                            {{ number_format($rate * 100, 1) }}%
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right {{ (int) $row->failures > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                                    {{ number_format((int) $row->failures) }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono">${{ number_format((float) $row->spent, 4) }}</td>
                                <td class="px-4 py-3 text-right text-gray-500 dark:text-gray-300">
                                    {{ $row->avg_ms === null ? '—' : number_format((float) $row->avg_ms).'ms' }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono">
                                    {{ $answers === 0 ? '—' : '$'.number_format((float) $row->spent / $answers, 4) }}
                                </td>
                                @php($bounced = (int) ($bouncedByFinder[$row->provider_name] ?? 0))
                                <td class="px-4 py-3 text-right {{ $bounced > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                                    {{-- Only a finder supplies an address, so only a finder can be answerable for one that bounced. --}}
                                    {{ $row->kind === \App\Models\EnrichmentProvider::KIND_FIND ? number_format($bounced) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-gray-500 dark:text-gray-300">
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
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    The free syntax and DNS checks rejected
                    <span class="font-medium">{{ number_format($rejectedFree) }}</span>
                    {{ Str::plural('address', $rejectedFree) }} before anything was paid for.
                </p>
            @endif
        </div>
    </div>
</div>
