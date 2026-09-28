<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Mailboxes</h2>
                <a href="{{ route('mailboxes.connect') }}"
                    class="px-4 py-2 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover transition">
                    + Connect Google mailbox
                </a>
            </div>

            @if (session('status'))
                <div class="rounded-md bg-band border border-rule p-3 text-sm text-ink">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-band border border-rule p-3 text-sm text-danger">{{ session('error') }}</div>
            @endif

            <p class="text-sm text-ink-dim">
                Mail is sent through the Gmail API from Google's servers, so IP reputation is handled by Google.
                What matters here: keep each domain's SPF/DKIM/DMARC green (see Health), let warmup ramp volume slowly, and watch bounce rates.
            </p>

            @forelse ($mailboxes as $mailbox)
                <div class="bg-surface border border-rule sm:rounded-card p-6 space-y-3" wire:key="mailbox-{{ $mailbox->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold text-ink">
                                {{ $mailbox->email }}
                                @if ($mailbox->display_name)
                                    <span class="font-normal text-ink-dim">({{ $mailbox->display_name }})</span>
                                @endif
                            </p>
                            <p class="text-xs text-ink-dim">
                                {{ $mailbox->domain->name }}
                                · sent today {{ $sentToday->get($mailbox->id, 0) }}/{{ $mailbox->effectiveDailyCap() }}
                                @if ($mailbox->isWarming())
                                    · warming (day {{ (int) $mailbox->warmup_started_at->diffInDays(now()) + 1 }}, cap {{ $mailbox->effectiveDailyCap() }} of {{ $mailbox->daily_cap }})
                                @endif
                                · window {{ $mailbox->send_window_start }}–{{ $mailbox->send_window_end }} {{ $mailbox->send_timezone }}
                            </p>
                            @if ($mailbox->paused_reason)
                                {{-- Why it stopped. A record of the past, written once. --}}
                                <p class="text-xs text-ink-dim mt-1">{{ $mailbox->paused_reason }}</p>
                            @endif

                            @php($blocker = $blockers[$mailbox->id] ?? null)

                            @if ($blocker)
                                {{-- Whether that reason still holds, re-checked on every page
                                     load. Without this the row kept reporting a problem that
                                     had already been fixed, and there was no way to tell. --}}
                                @if ($blocker['reason'])
                                    <p class="text-xs text-danger mt-1 font-medium">Still blocked: {{ $blocker['reason'] }}</p>
                                @else
                                    <p class="text-xs text-ink mt-1 font-medium">
                                        Nothing is blocking this mailbox any more. Safe to resume.
                                    </p>
                                @endif

                                @if ($blocker['summary'])
                                    <p class="text-xs text-ink-dim">{{ $mailbox->domain?->name }}: {{ $blocker['summary'] }}</p>
                                @endif
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <x-pill :tone="match (true) { $mailbox->status === 'active' => 'good', $mailbox->status === 'paused' => 'warn', in_array($mailbox->status, ['disconnected', 'error']) => 'danger', default => 'neutral' }">{{ $mailbox->status }}</x-pill>
                            <span class="text-xs text-ink-dim">bounce {{ number_format($mailbox->bounce_rate_7d * 100, 1) }}% · reply {{ number_format($mailbox->reply_rate_7d * 100, 1) }}% (7d)</span>
                            @if ($mailbox->status === 'active')
                                <button wire:click="pause({{ $mailbox->id }})" class="px-3 py-1.5 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">Pause</button>
                            @elseif ($mailbox->status === 'paused')
                                <button wire:click="resume({{ $mailbox->id }})" class="px-3 py-1.5 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">Resume</button>
                            @elseif ($mailbox->status === 'disconnected')
                                <a href="{{ route('mailboxes.connect') }}" class="px-3 py-1.5 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">Reconnect</a>
                            @endif

                            @if ($mailbox->status !== 'disconnected')
                                <button wire:click="disconnect({{ $mailbox->id }})"
                                    wire:confirm="Disconnect {{ $mailbox->email }}?&#10;&#10;This signs the account out and deletes the stored credentials, so it stops sending and receiving. Anything queued on it moves to another mailbox.&#10;&#10;Its history is kept, but getting it back means signing in at Google again."
                                    class="px-3 py-1.5 rounded-md border border-danger text-danger text-sm font-semibold hover:bg-danger/10 transition">Disconnect</button>
                            @endif

                            <button wire:click="edit({{ $mailbox->id }})" class="px-3 py-1.5 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">Settings</button>
                        </div>
                    </div>

                    @if ($editingId === $mailbox->id)
                        <form wire:submit="save" class="border-t border-rule pt-4 grid sm:grid-cols-3 gap-4 text-sm">
                            <div>
                                <x-input-label value="Sender display name" />
                                <x-text-input wire:model="form.display_name" class="mt-1 w-full" />
                            </div>
                            <div>
                                <x-input-label value="Daily cap" />
                                <x-text-input type="number" wire:model="form.daily_cap" class="mt-1 w-full" />
                                <x-input-error :messages="$errors->get('form.daily_cap')" class="mt-1" />
                            </div>
                            <div class="flex gap-2">
                                <div>
                                    <x-input-label value="Min gap (min)" />
                                    <x-text-input type="number" wire:model="form.min_gap_minutes" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <x-input-label value="Max gap (min)" />
                                    <x-text-input type="number" wire:model="form.max_gap_minutes" class="mt-1 w-full" />
                                    <x-input-error :messages="$errors->get('form.max_gap_minutes')" class="mt-1" />
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <div>
                                    <x-input-label value="Window start" />
                                    <x-text-input wire:model="form.send_window_start" placeholder="09:00" class="mt-1 w-full" />
                                    <x-input-error :messages="$errors->get('form.send_window_start')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Window end" />
                                    <x-text-input wire:model="form.send_window_end" placeholder="17:00" class="mt-1 w-full" />
                                    <x-input-error :messages="$errors->get('form.send_window_end')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <x-input-label value="Timezone" />
                                <x-text-input wire:model="form.send_timezone" placeholder="Europe/London" class="mt-1 w-full" />
                                <x-input-error :messages="$errors->get('form.send_timezone')" class="mt-1" />
                            </div>
                            <div class="flex items-end gap-4 pb-1">
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" wire:model="form.send_weekends" class="rounded border-rule-strong text-brand focus:ring-brand"> Weekends
                                </label>
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" wire:model="form.warmup_enabled" class="rounded border-rule-strong text-brand focus:ring-brand"> Warmup
                                </label>
                            </div>
                            <div class="sm:col-span-3 flex gap-2">
                                <x-primary-button>Save</x-primary-button>
                                <x-secondary-button wire:click="cancelEdit" type="button">Cancel</x-secondary-button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <div class="bg-surface border border-rule sm:rounded-card p-10 text-center text-ink-dim">
                    No mailboxes connected yet. Connect a Google Workspace mailbox to start sending.
                    <br>See <code class="font-mono text-sm">docs/google-cloud-setup.md</code> for the one-time Google Cloud setup.
                </div>
            @endforelse
        </div>
    </div>
</div>
