<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Mailboxes</h2>
                <a href="{{ route('mailboxes.connect') }}"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                    + Connect Google mailbox
                </a>
            </div>

            @if (session('status'))
                <div class="rounded-md bg-green-50 dark:bg-green-900/30 p-3 text-sm text-green-800 dark:text-green-200">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-800 dark:text-red-200">{{ session('error') }}</div>
            @endif

            <p class="text-sm text-gray-500">
                Mail is sent through the Gmail API from Google's servers, so IP reputation is handled by Google.
                What matters here: keep each domain's SPF/DKIM/DMARC green (see Health), let warmup ramp volume slowly, and watch bounce rates.
            </p>

            @forelse ($mailboxes as $mailbox)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-3" wire:key="mailbox-{{ $mailbox->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100">
                                {{ $mailbox->email }}
                                @if ($mailbox->display_name)
                                    <span class="font-normal text-gray-500">({{ $mailbox->display_name }})</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ $mailbox->domain->name }}
                                · sent today {{ $sentToday->get($mailbox->id, 0) }}/{{ $mailbox->effectiveDailyCap() }}
                                @if ($mailbox->isWarming())
                                    · warming (day {{ (int) $mailbox->warmup_started_at->diffInDays(now()) + 1 }}, cap {{ $mailbox->effectiveDailyCap() }} of {{ $mailbox->daily_cap }})
                                @endif
                                · window {{ $mailbox->send_window_start }}–{{ $mailbox->send_window_end }} {{ $mailbox->send_timezone }}
                            </p>
                            @if ($mailbox->paused_reason)
                                <p class="text-xs text-red-500 mt-1">{{ $mailbox->paused_reason }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span @class([
                                'px-2 py-1 rounded text-xs font-semibold',
                                'bg-green-100 text-green-800' => $mailbox->status === 'active',
                                'bg-yellow-100 text-yellow-800' => $mailbox->status === 'paused',
                                'bg-red-100 text-red-800' => in_array($mailbox->status, ['disconnected', 'error']),
                            ])>{{ $mailbox->status }}</span>
                            <span class="text-xs text-gray-500">bounce {{ number_format($mailbox->bounce_rate_7d * 100, 1) }}% · reply {{ number_format($mailbox->reply_rate_7d * 100, 1) }}% (7d)</span>
                            @if ($mailbox->status === 'active')
                                <button wire:click="pause({{ $mailbox->id }})" class="text-xs text-yellow-700 hover:underline">Pause</button>
                            @elseif ($mailbox->status === 'paused')
                                <button wire:click="resume({{ $mailbox->id }})" class="text-xs text-green-700 hover:underline">Resume</button>
                            @elseif ($mailbox->status === 'disconnected')
                                <a href="{{ route('mailboxes.connect') }}" class="text-xs text-indigo-600 hover:underline">Reconnect</a>
                            @endif
                            <button wire:click="edit({{ $mailbox->id }})" class="text-xs text-indigo-600 hover:underline">Settings</button>
                        </div>
                    </div>

                    @if ($editingId === $mailbox->id)
                        <form wire:submit="save" class="border-t dark:border-gray-700 pt-4 grid sm:grid-cols-3 gap-4 text-sm">
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
                                    <input type="checkbox" wire:model="form.send_weekends" class="rounded border-gray-300"> Weekends
                                </label>
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" wire:model="form.warmup_enabled" class="rounded border-gray-300"> Warmup
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
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-10 text-center text-gray-500">
                    No mailboxes connected yet. Connect a Google Workspace mailbox to start sending.
                    <br>See <code class="font-mono text-xs">docs/google-cloud-setup.md</code> for the one-time Google Cloud setup.
                </div>
            @endforelse
        </div>
    </div>
</div>
