<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    Contacts <span class="text-sm font-normal text-gray-500">{{ $contacts->total() }}</span>
                </h2>
                <button wire:click="toggleFilters"
                    class="px-3 py-2 border dark:border-gray-700 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Filters
                    @if ($this->activeFilterCount() > 0)
                        <span class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-indigo-600 text-white">{{ $this->activeFilterCount() }}</span>
                    @endif
                </button>
            </div>

            @if ($showFilters)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 grid sm:grid-cols-3 gap-4 text-sm">
                    @foreach (['email' => 'Email', 'name' => 'Name', 'company' => 'Company'] as $field => $label)
                        <div>
                            <x-input-label :value="$label" />
                            <x-text-input wire:model.live.debounce.300ms="{{ $field }}" list="suggest-{{ $field }}" class="mt-1 w-full" placeholder="Type to search…" />
                            <datalist id="suggest-{{ $field }}">
                                @foreach ($this->suggestions($field, $this->{$field}) as $suggestion)
                                    <option value="{{ $suggestion }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                    @endforeach

                    <div>
                        <x-input-label value="Source app" />
                        <div class="mt-1 space-y-1 max-h-28 overflow-y-auto">
                            @forelse ($availableSources as $source)
                                <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model.live="sources" value="{{ $source }}" class="rounded border-gray-300"> {{ $source }}
                                </label>
                            @empty
                                <p class="text-gray-400 text-xs">No sources yet</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Enrollment status" />
                        <div class="mt-1 space-y-1 max-h-64 overflow-y-auto">
                            @foreach ($availableStatuses as $status)
                                <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model.live="enrollmentStatuses" value="{{ $status }}" class="rounded border-gray-300"> {{ str_replace('_', ' ', $status) }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div>
                            <x-input-label value="Suppressed" />
                            <select wire:model.live="suppressed" class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md text-sm">
                                <option value="">Any</option>
                                <option value="yes">Suppressed only</option>
                                <option value="no">Not suppressed</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <div>
                                <x-input-label value="Added from" />
                                <x-text-input type="date" wire:model.live="createdFrom" class="mt-1 w-full" />
                            </div>
                            <div>
                                <x-input-label value="to" />
                                <x-text-input type="date" wire:model.live="createdTo" class="mt-1 w-full" />
                            </div>
                        </div>
                    </div>

                    <div class="sm:col-span-3">
                        <button wire:click="clearFilters" class="text-sm text-indigo-600 hover:underline">Clear all</button>
                    </div>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <x-index.sort-header field="email" :sort-field="$sortField" :sort-direction="$sortDirection">Email</x-index.sort-header>
                            <x-index.sort-header field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-index.sort-header>
                            <x-index.sort-header field="company" :sort-field="$sortField" :sort-direction="$sortDirection">Company</x-index.sort-header>
                            <x-index.sort-header field="source" :sort-field="$sortField" :sort-direction="$sortDirection">Source</x-index.sort-header>
                            <th class="px-6 py-3 font-medium">Enrollments</th>
                            <x-index.sort-header field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Added</x-index.sort-header>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @forelse ($contacts as $contact)
                            <tr wire:key="contact-{{ $contact->id }}" class="{{ $suppressedEmails->has($contact->email) ? 'opacity-60' : '' }}">
                                <td class="px-6 py-3">
                                    {{ $contact->email }}
                                    @if ($suppressedEmails->has($contact->email))
                                        <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-red-100 text-red-700">suppressed</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3">{{ $contact->name }}</td>
                                <td class="px-6 py-3">{{ $contact->company }}</td>
                                <td class="px-6 py-3 text-xs font-mono">{{ $contact->source }}</td>
                                <td class="px-6 py-3">{{ $contact->enrollments_count }}</td>
                                <td class="px-6 py-3 text-xs text-gray-500">{{ $contact->created_at->timezone(config('outreach.timezone'))->format('j M Y') }}</td>
                                <td class="px-6 py-3 text-right">
                                    <button wire:click="toggleExpand({{ $contact->id }})" class="text-indigo-600 hover:underline text-xs">
                                        {{ $expandedId === $contact->id ? 'Hide' : 'Detail' }}
                                    </button>
                                </td>
                            </tr>
                            @if ($expanded && $expandedId === $contact->id)
                                <tr wire:key="contact-detail-{{ $contact->id }}">
                                    <td colspan="7" class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40">
                                        <div class="space-y-3 text-sm">
                                            <div class="flex items-center justify-between">
                                                <p class="font-semibold">Enrollments &amp; messages</p>
                                                @if ($suppressedEmails->has($contact->email))
                                                    <button wire:click="unsuppress({{ $contact->id }})" class="text-xs text-green-700 hover:underline">Remove suppression</button>
                                                @else
                                                    <button wire:click="suppress({{ $contact->id }})"
                                                        wire:confirm="Suppress this contact? All active sequences stop and they can never be emailed again."
                                                        class="text-xs text-red-600 hover:underline">Suppress contact</button>
                                                @endif
                                            </div>
                                            @if (!empty($contact->custom))
                                                <p class="text-xs text-gray-500 font-mono">{{ json_encode($expanded->custom) }}</p>
                                            @endif
                                            @forelse ($expanded->enrollments as $enrollment)
                                                <div class="border dark:border-gray-700 rounded p-3">
                                                    <p>
                                                        <span class="font-mono text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-800">{{ $enrollment->automation->tag }}</span>
                                                        <span class="ml-2 text-xs">{{ str_replace('_', ' ', $enrollment->status) }}</span>
                                                        @if ($enrollment->mailbox)
                                                            <span class="ml-2 text-xs text-gray-500">via {{ $enrollment->mailbox->email }}</span>
                                                        @endif
                                                        @if ($enrollment->stop_reason)
                                                            <span class="ml-2 text-xs text-gray-400">({{ $enrollment->stop_reason }})</span>
                                                        @endif
                                                    </p>
                                                    <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                                        @foreach ($expanded->messages->where('enrollment_id', $enrollment->id)->sortBy(fn ($m) => $m->sequenceStep->position) as $message)
                                                            <li>
                                                                Step {{ $message->sequenceStep->position }} — {{ str_replace('_', ' ', $message->status) }}
                                                                @if ($message->sent_at) · sent {{ $message->sent_at->timezone(config('outreach.timezone'))->format('j M H:i') }}
                                                                @elseif ($message->scheduled_at) · sending {{ $message->scheduled_at->timezone(config('outreach.timezone'))->format('j M H:i:s') }}
                                                                @elseif ($message->approved_at) · approved {{ $message->approved_at->timezone(config('outreach.timezone'))->format('j M H:i') }}, waiting for a mailbox
                                                                @endif
                                                                @if ($message->subject) · “{{ $message->subject }}” @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @empty
                                                <p class="text-gray-500">No enrollments.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                    @if ($this->activeFilterCount() > 0)
                                        No contacts match these filters. <button wire:click="clearFilters" class="text-indigo-600 hover:underline">Clear all</button>
                                    @else
                                        No contacts yet — they arrive via <code class="font-mono">POST /api/contacts</code> from your other apps.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $contacts->links() }}
        </div>
    </div>
</div>
