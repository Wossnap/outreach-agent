<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Dashboard</h2>
                <div class="flex items-center gap-1 rounded-md border border-rule p-1 bg-surface">
                    @foreach ($windows as $value => $label)
                        <button wire:click="$set('window', '{{ $value }}')"
                            @class([
                                'px-3 py-1.5 text-sm font-medium rounded transition',
                                'bg-brand text-brand-ink' => $window === (string) $value,
                                'text-ink-dim hover:bg-band' => $window !== (string) $value,
                            ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if (session('dashboard-status'))
                <pre class="rounded-md bg-band border border-rule p-3 font-mono text-xs text-ink whitespace-pre-wrap">{{ session('dashboard-status') }}</pre>
            @endif

            @php($tile = 'bg-surface border border-rule sm:rounded-card p-5')
            @php($label = 'text-xs font-semibold uppercase tracking-label text-ink-dim')
            @php($big = 'mt-1 text-3xl font-semibold text-ink')
            @php($sub = 'mt-1 text-xs text-ink-dim')
            @php($pct = fn (?float $rate) => $rate === null ? '—' : number_format($rate * 100, 1).'%')

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="{{ $tile }}">
                    <p class="{{ $label }}">Sent</p>
                    <p class="{{ $big }}">{{ number_format($totals['sent']) }}</p>
                    <p class="{{ $sub }}">emails delivered to Gmail</p>
                </div>
                <div class="{{ $tile }}">
                    <p class="{{ $label }}">Opened</p>
                    <p class="{{ $big }}">{{ number_format($totals['opened']) }}</p>
                    <p class="{{ $sub }}">{{ $pct($totals['open_rate']) }} of {{ number_format($totals['tracked']) }} tracked</p>
                </div>
                <div class="{{ $tile }}">
                    <p class="{{ $label }}">Replied</p>
                    <p class="{{ $big }}">{{ number_format($totals['replied']) }}</p>
                    <p class="{{ $sub }}">{{ $pct($totals['reply_rate']) }} of sent</p>
                </div>
                <div class="{{ $tile }}">
                    <p class="{{ $label }}">Bounced</p>
                    <p class="{{ $big }}">{{ number_format($totals['bounced']) }}</p>
                    <p class="{{ $sub }}">{{ $pct($totals['bounce_rate']) }} of sent</p>
                </div>
                <div class="{{ $tile }}">
                    <p class="{{ $label }}">Unsubscribed</p>
                    <p class="{{ $big }}">{{ number_format($totals['unsubscribed']) }}</p>
                    <p class="{{ $sub }}">asked us to stop</p>
                </div>
                <div class="{{ $tile }}">
                    <p class="{{ $label }}">Spam rate (Gmail)</p>
                    @if ($worstSpam)
                        <p class="{{ $big }}">{{ number_format($worstSpam->latestPostmasterStat->spam_rate * 100, 2) }}%</p>
                        <p class="{{ $sub }}">{{ $worstSpam->name }} · {{ $worstSpam->latestPostmasterStat->date->format('j M') }}</p>
                    @else
                        <p class="{{ $big }}">—</p>
                        <p class="{{ $sub }}">no Postmaster data yet · <a href="{{ route('health') }}" class="font-medium text-brand hover:underline" wire:navigate>Health</a></p>
                    @endif
                </div>
            </div>

            <p class="text-xs text-ink-dim">
                Opens are directional: image blocking undercounts them, and Gmail's image proxy and Apple's Mail Privacy Protection can count an open nobody made.
                The spam rate is what Gmail users reported, from Postmaster Tools; it is not whether an email landed in a spam folder, which nobody outside Google can see.
            </p>

            {{-- One column per day, three bars each. Heights are relative to
                 the busiest day so the shape is readable at any volume. --}}
            @php($max = max(1, collect($daily)->max(fn ($d) => max($d['sent'], $d['opened'], $d['replied']))))
            <div class="bg-surface border border-rule sm:rounded-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-ink">Per day</h3>
                    <div class="flex items-center gap-4 text-xs text-ink-dim">
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-brand"></span> sent</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-brand/55"></span> opened</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-brand/25"></span> replied</span>
                    </div>
                </div>
                <div class="h-40 flex items-end gap-px sm:gap-1">
                    @foreach ($daily as $day)
                        <div class="flex-1 h-full flex items-end justify-center gap-px"
                            title="{{ $day['day'] }}: sent {{ $day['sent'] }}, opened {{ $day['opened'] }}, replied {{ $day['replied'] }}">
                            <div class="w-1/3 bg-brand rounded-t-sm" style="height: {{ round($day['sent'] / $max * 100) }}%"></div>
                            <div class="w-1/3 bg-brand/55 rounded-t-sm" style="height: {{ round($day['opened'] / $max * 100) }}%"></div>
                            <div class="w-1/3 bg-brand/25 rounded-t-sm" style="height: {{ round($day['replied'] / $max * 100) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-xs text-ink-dim">
                    <span>{{ \Carbon\Carbon::parse($daily[0]['day'])->format('j M') }}</span>
                    <span>{{ \Carbon\Carbon::parse(end($daily)['day'])->format('j M') }}</span>
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-6">
                <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                    <table class="min-w-full divide-y divide-rule text-sm">
                        <thead class="text-left">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Automation</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Sent</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Opened</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Replied</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule text-ink">
                            @forelse ($byAutomation as $row)
                                <tr>
                                    <td class="px-6 py-3 font-medium">{{ $row['name'] }}</td>
                                    <td class="px-6 py-3">{{ number_format($row['sent']) }}</td>
                                    <td class="px-6 py-3">{{ number_format($row['opened']) }} <span class="text-xs text-ink-dim">{{ $pct($row['open_rate']) }}</span></td>
                                    <td class="px-6 py-3">{{ number_format($row['replied']) }} <span class="text-xs text-ink-dim">{{ $pct($row['reply_rate']) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-ink-dim">Nothing sent in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                    <div class="flex items-center justify-between px-6 pt-4">
                        <h3 class="text-base font-semibold text-ink">Gmail spam rate by domain</h3>
                        <button wire:click="syncPostmaster" wire:loading.attr="disabled"
                            class="text-xs font-medium text-brand hover:underline disabled:opacity-50">
                            <span wire:loading.remove wire:target="syncPostmaster">Sync now</span>
                            <span wire:loading wire:target="syncPostmaster">Syncing…</span>
                        </button>
                    </div>
                    <table class="min-w-full divide-y divide-rule text-sm mt-2">
                        <thead class="text-left">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Domain</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Spam rate</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Auth pass</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Reported</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule text-ink">
                            @forelse ($domains as $domain)
                                @php($stat = $domain->latestPostmasterStat)
                                <tr>
                                    <td class="px-6 py-3 font-medium">
                                        {{ $domain->name }}
                                        @if ($domain->postmaster_verification)
                                            <span class="block text-xs font-normal text-ink-dim">{{ strtolower($domain->postmaster_verification) }}</span>
                                        @endif
                                    </td>
                                    @if ($stat && $stat->spam_rate !== null)
                                        <td class="px-6 py-3"><x-spam-rate-badge :rate="$stat->spam_rate" /></td>
                                        <td class="px-6 py-3">{{ $stat->auth_success_rate === null ? '—' : number_format($stat->auth_success_rate * 100, 1).'%' }}</td>
                                        <td class="px-6 py-3 text-xs text-ink-dim">{{ $stat->date->format('j M Y') }}</td>
                                    @else
                                        <td class="px-6 py-3 text-xs text-ink-dim" colspan="3">
                                            @if ($domain->postmaster_error)
                                                <span class="text-danger">{{ $domain->postmaster_error }}</span>
                                            @elseif ($domain->postmaster_synced_at)
                                                No figures yet. Gmail only reports days with enough traffic from this domain.
                                            @else
                                                Not synced yet.
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-ink-dim">No sending domains yet. They appear when a mailbox is connected.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="px-6 py-3 text-xs text-ink-dim">
                        A domain reports once it is verified at postmaster.google.com by the Google account of a connected mailbox, and that mailbox has been reconnected since Postmaster access was added.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
