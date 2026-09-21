<div>
    <div class="py-6 sm:py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @php($pageIds = $messages->getCollection()->pluck('id')->all())
            @php($pageAllTicked = $pageIds !== [] && collect($pageIds)->every(fn ($id) => $selected[$id] ?? false))

            {{-- Sticky, so the bulk actions are to hand however far down the
                 queue the reading has got. --}}
            <div class="sticky top-0 z-10 bg-white dark:bg-gray-800 shadow sm:rounded-lg px-4 sm:px-6 py-3 space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" title="Select every draft on this page">
                        <input type="checkbox"
                            wire:click="toggleSelectPage({{ json_encode($pageIds) }})"
                            @checked($pageAllTicked)
                            @disabled($pageIds === [])
                            class="rounded border-gray-300 dark:border-gray-600">
                        <span class="sr-only sm:not-sr-only">All on page</span>
                    </label>

                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                        Approval queue
                        <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-300">{{ $messages->total() }} pending</span>
                    </h2>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <button wire:click="toggleView"
                            class="px-3 py-2 border dark:border-gray-700 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                            {{ $view === \App\Livewire\ApprovalQueue::VIEW_COMPACT ? 'Edit view' : 'Compact view' }}
                        </button>
                        <button wire:click="bulkApprove"
                            wire:confirm="Approve the {{ $selectedCount }} selected draft(s)? They will be scheduled for sending."
                            class="px-3 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-500 disabled:opacity-40"
                            @disabled($selectedCount === 0)>
                            Approve selected ({{ $selectedCount }})
                        </button>
                        <button wire:click="startRejectSelected"
                            class="px-3 py-2 border border-red-300 text-red-600 text-sm rounded-md hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/20 disabled:opacity-40"
                            @disabled($selectedCount === 0)>
                            Reject selected ({{ $selectedCount }})
                        </button>
                    </div>
                </div>

                @if ($rejectingSelected)
                    <div class="border-t dark:border-gray-700 pt-3 space-y-2">
                        <x-input-label value="Rejection note for all {{ $selectedCount }} (optional — for your records)" />
                        <x-text-input wire:model="rejectionNote" class="w-full" placeholder="e.g. wrong angle, too pushy" />
                        <p class="text-xs text-gray-500 dark:text-gray-400">Rejecting takes each lead out of their sequence.</p>
                        <div class="flex gap-2">
                            <x-danger-button wire:click="confirmReject">Confirm reject ({{ $selectedCount }})</x-danger-button>
                            <x-secondary-button wire:click="cancelReject">Cancel</x-secondary-button>
                        </div>
                    </div>
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
                @php($isFollowUp = ($message->sequenceStep?->position ?? 1) > 1)
                @php($threadSubject = 'Re: '.(($priorThreads[$message->enrollment_id] ?? null)?->first()?->subject ?? $message->subject))
                @php($editing = $this->isEditing($message->id))

                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg {{ ($selected[$message->id] ?? false) ? 'ring-2 ring-indigo-400' : '' }}" wire:key="message-{{ $message->id }}">
                    {{-- Who, which automation, and the actions, on one row. --}}
                    <div class="flex flex-wrap items-start gap-3 px-4 sm:px-6 py-3 border-b border-gray-100 dark:border-gray-700">
                        <input type="checkbox" wire:model.live="selected.{{ $message->id }}"
                            class="mt-1 rounded border-gray-300 dark:border-gray-600">

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 dark:text-gray-100 truncate">
                                {{ $message->contact->name ?: $message->contact->email }}
                                @if ($message->contact->company)
                                    <span class="font-normal text-gray-500 dark:text-gray-300">· {{ $message->contact->company }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-300">
                                to {{ $message->contact->email }}
                                · from {{ $message->mailbox?->email ?? 'no mailbox yet' }}
                                · drafted {{ $message->created_at->diffForHumans() }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 text-xs">
                            <span class="px-2 py-1 rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200 font-mono" title="{{ $message->enrollment->automation->name }}">
                                {{ $message->enrollment->automation->tag }}
                            </span>
                            <span class="px-2 py-1 rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                step {{ $message->sequenceStep->position }}
                            </span>
                            @if ($message->edited_by_user)
                                <span class="px-2 py-1 rounded bg-amber-100 text-amber-800">edited</span>
                            @endif
                        </div>

                        <div class="flex items-center gap-1">
                            <button wire:click="approve({{ $message->id }})"
                                class="px-3 py-1.5 bg-green-600 text-white text-xs rounded-md hover:bg-green-500">
                                Approve
                            </button>
                            <button wire:click="startReject({{ $message->id }})"
                                class="px-3 py-1.5 border border-red-300 text-red-600 text-xs rounded-md hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/20">
                                Reject
                            </button>
                            @if ($view === \App\Livewire\ApprovalQueue::VIEW_COMPACT)
                                <button wire:click="toggleEdit({{ $message->id }})"
                                    class="px-3 py-1.5 border dark:border-gray-700 text-xs rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    {{ $editing ? 'Done' : 'Edit' }}
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="px-4 sm:px-6 py-4 space-y-3">
                        @if (($priorThreads[$message->enrollment_id] ?? null)?->isNotEmpty())
                            <details class="text-sm">
                                <summary class="cursor-pointer text-gray-500 dark:text-gray-300 hover:text-gray-700">
                                    Prior emails in this thread ({{ $priorThreads[$message->enrollment_id]->count() }})
                                </summary>
                                <div class="mt-2 space-y-3 border-l-2 border-gray-200 dark:border-gray-700 pl-4">
                                    @foreach ($priorThreads[$message->enrollment_id] as $prior)
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-300">{{ $prior->sent_at?->timezone(config('outreach.timezone'))->format('D j M, H:i') }} — {{ $prior->subject }}</p>
                                            <p class="whitespace-pre-line text-gray-700 dark:text-gray-300 text-xs mt-1">{{ $prior->body_text }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        @if ($editing)
                            <div>
                                {{-- Follow-ups send as "Re: <first email's subject>" so they stay in the
                                     same conversation, and any edit here would be discarded on send. --}}
                                <x-input-label value="Subject" />
                                @if ($isFollowUp)
                                    <x-text-input class="mt-1 w-full bg-gray-100 dark:bg-gray-800" value="{{ $threadSubject }}" disabled readonly />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-300">Follow-ups keep the first email's subject so they stay in the same conversation.</p>
                                @else
                                    <x-text-input wire:model.blur="drafts.{{ $message->id }}.subject" class="mt-1 w-full" />
                                @endif
                            </div>
                            <div>
                                <x-input-label value="Body" />
                                <textarea wire:model.blur="drafts.{{ $message->id }}.body" rows="10"
                                    class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm text-sm"></textarea>
                            </div>
                        @else
                            {{-- The email as it would arrive: subject, then body. Read, not
                                 edited; Edit on the row opens the form for this one draft. --}}
                            <div class="text-sm">
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $isFollowUp ? $threadSubject : ($drafts[$message->id]['subject'] ?? $message->subject) }}
                                </p>
                                @if ($isFollowUp)
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-300">Follow-ups keep the first email's subject so they stay in the same conversation.</p>
                                @endif
                                <p class="mt-2 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $drafts[$message->id]['body'] ?? $message->body_text }}</p>
                            </div>
                        @endif

                        @php($attachments = $message->sequenceStep?->attachmentList() ?? [])

                        @if ($attachments)
                            {{-- Approving sends whatever is attached, so it must not be something
                                 you only find out afterwards. --}}
                            <div class="rounded-md bg-gray-50 dark:bg-gray-900 px-3 py-2 text-sm">
                                <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-300">Sends with</span>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($attachments as $attachment)
                                        <li class="text-gray-700 dark:text-gray-300">
                                            {{ $attachment['filename'] }}
                                            <span class="text-xs text-gray-500 dark:text-gray-300">({{ number_format($attachment['size'] / 1024, 0) }} KB)</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

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
                </div>
            @empty
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-10 text-center text-gray-500 dark:text-gray-300">
                    Nothing waiting for approval. Drafts appear here as soon as your apps send contacts to the API.
                </div>
            @endforelse

            {{ $messages->links() }}
        </div>
    </div>
</div>
