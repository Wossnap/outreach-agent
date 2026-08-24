<div>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    Approval queue
                    <span class="ml-2 text-sm font-normal text-gray-500">{{ $messages->total() }} pending</span>
                </h2>
                @if ($messages->total() > 0)
                    <button wire:click="bulkApprove"
                        wire:confirm="Approve all selected drafts? They will be scheduled for sending."
                        class="px-3 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-500 disabled:opacity-40"
                        @disabled(count(array_filter($selected)) === 0)>
                        Approve selected ({{ count(array_filter($selected)) }})
                    </button>
                @endif
            </div>

            @if (session('queue-status'))
                <div class="rounded-md bg-green-50 dark:bg-green-900/30 p-3 text-sm text-green-800 dark:text-green-200">{{ session('queue-status') }}</div>
            @endif
            @if (session('queue-warning'))
                <div class="rounded-md bg-yellow-50 dark:bg-yellow-900/30 p-3 text-sm text-yellow-800 dark:text-yellow-200">{{ session('queue-warning') }}</div>
            @endif

            @if ($waiting->isNotEmpty())
                <div class="rounded-md border border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 p-4 space-y-2">
                    <p class="text-sm font-semibold text-yellow-900 dark:text-yellow-200">
                        Waiting to send ({{ $waiting->count() }})
                    </p>
                    <p class="text-xs text-yellow-800 dark:text-yellow-300">
                        Approved, but no mailbox was available to send from. These are retried automatically. If they stay here, check the Mailboxes page for a paused or disconnected mailbox.
                    </p>
                    <ul class="text-xs text-yellow-900 dark:text-yellow-200 space-y-1">
                        @foreach ($waiting as $item)
                            <li>
                                {{ $item->contact?->email }}
                                — step {{ $item->sequenceStep?->position }}
                                — approved {{ $item->approved_at?->timezone(config('outreach.timezone'))->format('D j M, H:i') }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @forelse ($messages as $message)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-4" wire:key="message-{{ $message->id }}">
                    <div class="flex items-start justify-between gap-4">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model.live="selected.{{ $message->id }}"
                                class="mt-1 rounded border-gray-300 dark:border-gray-700">
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $message->contact->name ?: $message->contact->email }}
                                    @if ($message->contact->company)
                                        <span class="font-normal text-gray-500">· {{ $message->contact->company }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500">
                                    to {{ $message->contact->email }}
                                    · from {{ $message->mailbox?->email ?? 'no mailbox yet' }}
                                </p>
                            </div>
                        </label>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="px-2 py-1 rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200 font-mono">
                                {{ $message->enrollment->automation->tag }}
                            </span>
                            <span class="px-2 py-1 rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                step {{ $message->sequenceStep->position }}
                            </span>
                            @if ($message->edited_by_user)
                                <span class="px-2 py-1 rounded bg-amber-100 text-amber-800">edited</span>
                            @endif
                        </div>
                    </div>

                    @if (($priorThreads[$message->enrollment_id] ?? null)?->isNotEmpty())
                        <details class="text-sm">
                            <summary class="cursor-pointer text-gray-500 hover:text-gray-700">
                                Prior emails in this thread ({{ $priorThreads[$message->enrollment_id]->count() }})
                            </summary>
                            <div class="mt-2 space-y-3 border-l-2 border-gray-200 dark:border-gray-700 pl-4">
                                @foreach ($priorThreads[$message->enrollment_id] as $prior)
                                    <div>
                                        <p class="text-xs text-gray-500">{{ $prior->sent_at?->timezone(config('outreach.timezone'))->format('D j M, H:i') }} — {{ $prior->subject }}</p>
                                        <p class="whitespace-pre-line text-gray-700 dark:text-gray-300 text-xs mt-1">{{ $prior->body_text }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    <div>
                        {{-- Follow-ups send as "Re: <first email's subject>" so they stay in the
                             same conversation, and any edit here would be discarded on send. --}}
                        @if (($message->sequenceStep?->position ?? 1) > 1)
                            <x-input-label value="Subject" />
                            <x-text-input class="mt-1 w-full bg-gray-100 dark:bg-gray-800"
                                value="Re: {{ ($priorThreads[$message->enrollment_id] ?? null)?->first()?->subject ?? $message->subject }}"
                                disabled readonly />
                            <p class="mt-1 text-xs text-gray-500">Follow-ups keep the first email's subject so they stay in the same conversation.</p>
                        @else
                            <x-input-label value="Subject" />
                            <x-text-input wire:model.blur="drafts.{{ $message->id }}.subject" class="mt-1 w-full" />
                        @endif
                    </div>
                    <div>
                        <x-input-label value="Body" />
                        <textarea wire:model.blur="drafts.{{ $message->id }}.body" rows="10"
                            class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm text-sm"></textarea>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <button wire:click="approve({{ $message->id }})"
                                class="px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-500">
                                Approve &amp; schedule
                            </button>
                            <button wire:click="startReject({{ $message->id }})"
                                class="px-4 py-2 border border-red-300 text-red-600 text-sm rounded-md hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/20">
                                Reject
                            </button>
                        </div>
                        <p class="text-xs text-gray-400">drafted {{ $message->created_at->diffForHumans() }}</p>
                    </div>

                    @if ($rejectingId === $message->id)
                        <div class="border-t dark:border-gray-700 pt-4 space-y-2">
                            <x-input-label value="Rejection note (optional — for your records)" />
                            <x-text-input wire:model="rejectionNote" class="w-full" placeholder="e.g. wrong angle, too pushy" />
                            <div class="flex gap-2">
                                <x-danger-button wire:click="confirmReject">Confirm reject</x-danger-button>
                                <x-secondary-button wire:click="cancelReject">Cancel</x-secondary-button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-10 text-center text-gray-500">
                    Nothing waiting for approval. Drafts appear here as soon as your apps send contacts to the API.
                </div>
            @endforelse

            {{ $messages->links() }}
        </div>
    </div>
</div>
