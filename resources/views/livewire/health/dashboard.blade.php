<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Deliverability health</h2>
                <button wire:click="runChecks" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500 disabled:opacity-50">
                    <span wire:loading.remove wire:target="runChecks">Run checks now</span>
                    <span wire:loading wire:target="runChecks">Checking…</span>
                </button>
            </div>

            @if (session('health-status'))
                <div class="rounded-md bg-green-50 dark:bg-green-900/30 p-3 text-sm text-green-800 dark:text-green-200">{{ session('health-status') }}</div>
            @endif

            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-md p-4 text-sm text-blue-900 dark:text-blue-200">
                Mail is sent through the Gmail API, so it leaves <strong>Google's IPs</strong> — sending-IP reputation and proxies are not a factor here.
                What this system owns and monitors: <strong>domain authentication</strong> (SPF/DKIM/DMARC), <strong>domain blocklists</strong>, and
                <strong>engagement</strong> (bounce and reply rates, volume discipline via caps, warmup and randomized pacing).
                Mailboxes auto-pause when thresholds breach; resuming is manual once the cause is fixed.
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">Domain</th>
                            <th class="px-6 py-3 font-medium">SPF</th>
                            <th class="px-6 py-3 font-medium">DKIM</th>
                            <th class="px-6 py-3 font-medium">DMARC</th>
                            <th class="px-6 py-3 font-medium">Blocklists</th>
                            <th class="px-6 py-3 font-medium">Mailboxes</th>
                            <th class="px-6 py-3 font-medium">Checked</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @forelse ($domains as $domain)
                            <tr>
                                <td class="px-6 py-3 font-medium">{{ $domain->name }}</td>
                                @foreach (['spf_status', 'dkim_status', 'dmarc_status'] as $field)
                                    <td class="px-6 py-3">
                                        <span @class([
                                            'px-2 py-1 rounded text-xs font-semibold',
                                            'bg-green-100 text-green-800' => $domain->{$field} === 'ok',
                                            'bg-yellow-100 text-yellow-800' => $domain->{$field} === 'warn',
                                            'bg-red-100 text-red-800' => $domain->{$field} === 'missing',
                                            'bg-gray-100 text-gray-600' => $domain->{$field} === 'unknown',
                                        ])>{{ $domain->{$field} }}</span>
                                    </td>
                                @endforeach
                                <td class="px-6 py-3">
                                    @if ($domain->dnsbl_listed)
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-red-100 text-red-800">{{ implode(', ', $domain->dnsbl_zones ?? []) }}</span>
                                    @else
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-800">clear</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3">{{ $domain->mailboxes_count }}</td>
                                <td class="px-6 py-3 text-gray-500 text-xs">{{ $domain->last_dns_checked_at?->diffForHumans() ?? 'never' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">No domains yet — they appear automatically when you connect a mailbox.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">Mailbox</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                            <th class="px-6 py-3 font-medium">Health</th>
                            <th class="px-6 py-3 font-medium">Sent (7d)</th>
                            <th class="px-6 py-3 font-medium">Bounce (7d)</th>
                            <th class="px-6 py-3 font-medium">Reply (7d)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @forelse ($mailboxes as $mailbox)
                            <tr>
                                <td class="px-6 py-3 font-medium">{{ $mailbox->email }}</td>
                                <td class="px-6 py-3">{{ $mailbox->status }}</td>
                                <td class="px-6 py-3">
                                    <span @class([
                                        'px-2 py-1 rounded text-xs font-semibold',
                                        'bg-green-100 text-green-800' => $mailbox->health_status === 'healthy',
                                        'bg-yellow-100 text-yellow-800' => $mailbox->health_status === 'warning',
                                        'bg-red-100 text-red-800' => $mailbox->health_status === 'critical',
                                        'bg-gray-100 text-gray-600' => $mailbox->health_status === 'unknown',
                                    ])>{{ $mailbox->health_status }}</span>
                                </td>
                                <td class="px-6 py-3">{{ $mailbox->sent_7d }}</td>
                                <td class="px-6 py-3">{{ number_format($mailbox->bounce_rate_7d * 100, 1) }}%</td>
                                <td class="px-6 py-3">{{ number_format($mailbox->reply_rate_7d * 100, 1) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No mailboxes connected yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($pauseEvents->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-3">Recent auto-pause / connection events</h3>
                    <ul class="space-y-2 text-sm">
                        @foreach ($pauseEvents as $event)
                            <li class="text-gray-700 dark:text-gray-300">
                                <span class="text-xs text-gray-400">{{ $event->created_at->timezone(config('outreach.timezone'))->format('j M H:i') }}</span>
                                — {{ $event->message }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
