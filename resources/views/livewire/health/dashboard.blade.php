<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Deliverability health</h2>
                <button wire:click="runChecks" wire:loading.attr="disabled"
                    class="px-4 py-2 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover disabled:opacity-50 transition">
                    <span wire:loading.remove wire:target="runChecks">Run checks now</span>
                    <span wire:loading wire:target="runChecks">Checking…</span>
                </button>
            </div>

            @if (session('health-results'))
                {{-- Per domain, including the ones that are fine. A bare
                     "completed" gave no way to tell a clean result from a
                     check that never really ran. --}}
                <div class="rounded-md bg-band border border-rule p-4 text-sm text-ink space-y-1">
                    <p class="font-medium text-ink">Check finished</p>
                    @foreach (session('health-results') as $result)
                        <p @class([
                            'text-ink' => $result['ok'],
                            'text-danger' => ! $result['ok'],
                        ])>
                            <span class="font-medium">{{ $result['domain'] }}:</span> {{ $result['text'] }}
                        </p>
                    @endforeach
                </div>
            @endif

            @if (session('health-status'))
                <div class="rounded-md bg-band border border-rule p-3 text-sm text-ink">{{ session('health-status') }}</div>
            @endif

            <div class="bg-band border border-rule rounded-md p-4 text-sm text-ink">
                Mail is sent through the Gmail API, so it leaves <strong>Google's IPs</strong>, so sending-IP reputation and proxies are not a factor here.
                What this system owns and monitors: <strong>domain authentication</strong> (SPF/DKIM/DMARC), <strong>domain blocklists</strong>, and
                <strong>engagement</strong> (bounce, reply and open rates, volume discipline via caps, warmup and randomized pacing),
                and <strong>Gmail's own spam rate</strong> per domain from Postmaster Tools.
                Mailboxes auto-pause when thresholds breach; resuming is manual once the cause is fixed.
            </div>

            @if ($postmasterScopeMissing)
                <div class="rounded-md bg-band border border-rule p-4 text-sm text-ink">
                    No connected mailbox has the Postmaster Tools scope yet, so Gmail spam rates cannot be read.
                    <a href="{{ route('mailboxes.index') }}" class="font-medium text-brand hover:underline" wire:navigate>Reconnect a mailbox</a> whose Google account has verified your domains at postmaster.google.com.
                </div>
            @endif

            <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="text-left">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Domain</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">SPF</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">DKIM</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">DMARC</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Blocklists</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Spam rate (Gmail)</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Mailboxes</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Checked</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule text-ink">
                        @forelse ($domains as $domain)
                            {{-- A domain nothing sends from is not scored, so its records are
                                 shown in grey whatever they say. Colouring them would
                                 contradict the "not in use" label sitting next to them and
                                 put a red badge on something that cannot affect anything. --}}
                            @php($scored = $domain->sendingMailboxCount() > 0)
                            <tr @class(['opacity-60' => ! $scored])>
                                <td class="px-6 py-3 font-medium">
                                    {{ $domain->name }}
                                    @unless ($scored)
                                        <x-pill tone="neutral" class="ml-2">not in use</x-pill>
                                    @endunless
                                </td>
                                @foreach (['spf_status', 'dkim_status', 'dmarc_status'] as $field)
                                    <td class="px-6 py-3">
                                        @php($tone = match (true) { ! $scored || in_array($domain->{$field}, ['unknown', 'absent'], true) => 'neutral', in_array($domain->{$field}, ['ok', 'monitoring', 'enforcing'], true) => 'good', $domain->{$field} === 'warn' => 'warn', $domain->{$field} === 'missing' => 'danger', default => 'neutral' })
                                        <x-pill :tone="$tone">{{ $domain->statusLabel($field) }}</x-pill>
                                    </td>
                                @endforeach
                                <td class="px-6 py-3">
                                    @if ($domain->dnsbl_listed)
                                        <x-pill :tone="$scored ? 'danger' : 'neutral'">{{ implode(', ', $domain->dnsbl_zones ?? []) }}</x-pill>
                                    @else
                                        <x-pill :tone="$scored ? 'good' : 'neutral'">clear</x-pill>
                                    @endif
                                </td>
                                <td class="px-6 py-3">
                                    @php($stat = $domain->latestPostmasterStat)
                                    @if ($stat && $stat->spam_rate !== null)
                                        <x-spam-rate-badge :rate="$stat->spam_rate" />
                                        <span class="block text-xs text-ink-dim mt-1">{{ $stat->date->format('j M') }}@if ($domain->postmaster_verification) · {{ strtolower($domain->postmaster_verification) }}@endif</span>
                                    @elseif ($domain->postmaster_error)
                                        <span class="text-xs text-danger">{{ $domain->postmaster_error }}</span>
                                    @elseif ($domain->postmaster_synced_at)
                                        <span class="text-xs text-ink-dim">no data yet · synced {{ $domain->postmaster_synced_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-xs text-ink-dim">not synced yet</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3">{{ $domain->mailboxes_count }}</td>
                                <td class="px-6 py-3 text-ink-dim text-xs">{{ $domain->last_dns_checked_at?->diffForHumans() ?? 'never' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-6 py-8 text-center text-ink-dim">No domains yet. They appear automatically when you connect a mailbox.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="text-left">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Mailbox</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Health</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Sent (7d)</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Open (7d)</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Bounce (7d)</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Reply (7d)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule text-ink">
                        @forelse ($mailboxes as $mailbox)
                            <tr>
                                <td class="px-6 py-3 font-medium">{{ $mailbox->email }}</td>
                                <td class="px-6 py-3">{{ $mailbox->status }}</td>
                                <td class="px-6 py-3">
                                    <x-pill :tone="match ($mailbox->health_status) { 'healthy' => 'good', 'warning' => 'warn', 'critical' => 'danger', 'unknown' => 'neutral', default => 'neutral' }">{{ $mailbox->health_status }}</x-pill>
                                </td>
                                <td class="px-6 py-3">{{ $mailbox->sent_7d }}</td>
                                <td class="px-6 py-3">{{ number_format($mailbox->open_rate_7d * 100, 1) }}%</td>
                                <td class="px-6 py-3">{{ number_format($mailbox->bounce_rate_7d * 100, 1) }}%</td>
                                <td class="px-6 py-3">{{ number_format($mailbox->reply_rate_7d * 100, 1) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-ink-dim">No mailboxes connected yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($pauseEvents->isNotEmpty())
                <div class="bg-surface border border-rule sm:rounded-card p-6">
                    <h3 class="text-base font-semibold text-ink mb-3">Recent auto-pause / connection events</h3>
                    <ul class="space-y-2 text-sm">
                        @foreach ($pauseEvents as $event)
                            <li class="text-ink">
                                <span class="text-xs text-ink-dim">{{ $event->created_at->timezone(config('outreach.timezone'))->format('j M H:i') }}</span>
                                {{ $event->message }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
