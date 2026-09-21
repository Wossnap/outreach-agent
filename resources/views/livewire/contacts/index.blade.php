<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Above the row, not inside it: as a flex child it pushed the
                 heading sideways every time a message appeared. --}}
            @if ($flash)
                <div class="rounded-md bg-blue-50 dark:bg-blue-900/30 p-3 text-sm text-blue-800 dark:text-blue-200">
                    {{ $flash }}
                </div>
            @endif

            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    Leads <span class="text-sm font-normal text-gray-500 dark:text-gray-300">{{ $contacts->total() }}</span>
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

                    <x-index.multi-select model="roles" label="Role" :options="$availableRoles" :selected="$roles" placeholder="Any role" empty="No roles yet" />

                    <x-index.multi-select model="categories" label="Category" :options="$availableCategories" :selected="$categories" placeholder="Any category" empty="No categories yet" />

                    <x-index.multi-select model="niches" label="Niche" :options="$availableNiches" :selected="$niches" placeholder="Any niche" empty="No niches yet" />

                    <x-index.multi-select model="sources" label="Source app" :options="$availableSources" :selected="$sources" placeholder="Any source" empty="No sources yet" />

                    <div>
                        <x-index.multi-select model="emailStatuses" label="Address" :options="$availableEmailStatuses" :selected="$emailStatuses" placeholder="Any status" />
                        <label class="mt-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model.live="includeNotFound" class="rounded border-gray-300 dark:border-gray-600">
                            Include leads with no address found
                        </label>
                    </div>

                    <x-index.multi-select model="enrollmentStatuses" label="Enrollment status" :options="$availableStatuses" :selected="$enrollmentStatuses" placeholder="Any status" />

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

            {{-- Only when something is ticked. An action that costs money at six
                 providers should not sit on the page inviting a stray click. --}}
            @if (count($selected) > 0)
                <div class="flex flex-wrap items-center gap-3 p-3 rounded-md bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800">
                    <span class="text-sm text-indigo-900 dark:text-indigo-200">{{ count($selected) }} selected</span>
                    <button wire:click="recheckSelected"
                        wire:confirm="Send {{ count($selected) }} lead(s) back through the waterfall?&#10;&#10;Each one starts again from the first provider, and every provider it reaches is paid for again. This is worth doing after adding or reordering one, not as a matter of routine.&#10;&#10;Anybody who has opted out is skipped."
                        class="px-3 py-2 text-sm rounded-md bg-indigo-600 hover:bg-indigo-700 text-white">
                        Check the addresses again
                    </button>
                    <button wire:click="$set('selected', [])" class="text-sm text-indigo-700 dark:text-indigo-300 hover:underline">Clear the selection</button>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-300">
                        <tr>
                            <th class="px-6 py-3 w-8">
                                @php($pageIds = $contacts->getCollection()->pluck('id')->all())
                                <input type="checkbox"
                                    wire:click="toggleSelectPage({{ json_encode($pageIds) }})"
                                    @checked($pageIds !== [] && array_diff(array_map(strval(...), $pageIds), $selected) === [])
                                    title="Select the leads on this page"
                                    class="rounded border-gray-300 dark:border-gray-600">
                            </th>
                            {{-- The detail toggle sits here, beside the checkbox, because on a
                                 table this wide a column at the far right scrolls out of view. --}}
                            <th class="px-2 py-3 w-8"></th>
                            <x-index.sort-header field="email" :sort-field="$sortField" :sort-direction="$sortDirection">Email</x-index.sort-header>
                            <x-index.sort-header field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-index.sort-header>
                            <x-index.sort-header field="company" :sort-field="$sortField" :sort-direction="$sortDirection">Company</x-index.sort-header>
                            <x-index.sort-header field="role" :sort-field="$sortField" :sort-direction="$sortDirection">Role</x-index.sort-header>
                            <x-index.sort-header field="category" :sort-field="$sortField" :sort-direction="$sortDirection">Category</x-index.sort-header>
                            <x-index.sort-header field="niche" :sort-field="$sortField" :sort-direction="$sortDirection">Niche</x-index.sort-header>
                            <x-index.sort-header field="email_status" :sort-field="$sortField" :sort-direction="$sortDirection">Address</x-index.sort-header>
                            <x-index.sort-header field="source" :sort-field="$sortField" :sort-direction="$sortDirection">Source</x-index.sort-header>
                            <th class="px-6 py-3 font-medium">Enrollments</th>
                            <x-index.sort-header field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Added</x-index.sort-header>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @forelse ($contacts as $contact)
                            <tr wire:key="contact-{{ $contact->id }}" class="{{ $suppressedEmails->has($contact->email) ? 'opacity-60' : '' }}">
                                <td class="px-6 py-3">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $contact->id }}"
                                        class="rounded border-gray-300 dark:border-gray-600">
                                </td>
                                <td class="px-2 py-3">
                                    <button wire:click="toggleExpand({{ $contact->id }})"
                                        title="{{ $expandedId === $contact->id ? 'Hide detail' : 'Show detail' }}"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs whitespace-nowrap">
                                        {{ $expandedId === $contact->id ? '▾ Hide' : '▸ Detail' }}
                                    </button>
                                </td>
                                <td class="px-6 py-3">
                                    {{ $contact->email }}
                                    @if ($suppressedEmails->has($contact->email))
                                        <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-red-100 text-red-700">suppressed</span>
                                    @endif
                                </td>
                                {{-- The name opens the person's LinkedIn profile and the company
                                     its page, when either is on file. Plain text otherwise, so a
                                     link always goes somewhere. --}}
                                <td class="px-6 py-3">
                                    @if ($contact->name && $contact->profile_url)
                                        <a href="{{ $contact->profile_url }}" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $contact->name }}</a>
                                    @else
                                        {{ $contact->name }}
                                    @endif
                                </td>
                                <td class="px-6 py-3">
                                    @if ($contact->company && $contact->company_url)
                                        <a href="{{ $contact->company_url }}" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $contact->company }}</a>
                                    @else
                                        {{ $contact->company }}
                                    @endif
                                </td>
                                <td class="px-6 py-3">{{ $contact->role }}</td>
                                <td class="px-6 py-3">{{ $contact->category }}</td>
                                <td class="px-6 py-3">{{ $contact->niche }}</td>
                                <td class="px-6 py-3">
                                    @php($status = $contact->email_status)
                                    <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap
                                        @class([
                                            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $status === \App\Models\Contact::EMAIL_VALID,
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $status === \App\Models\Contact::EMAIL_RISKY,
                                            'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' => in_array($status, [\App\Models\Contact::EMAIL_INVALID, \App\Models\Contact::EMAIL_NOT_FOUND], true),
                                            'bg-gray-100 text-gray-600' => ! in_array($status, [\App\Models\Contact::EMAIL_VALID, \App\Models\Contact::EMAIL_RISKY, \App\Models\Contact::EMAIL_INVALID, \App\Models\Contact::EMAIL_NOT_FOUND], true),
                                        ])">
                                        {{ \App\Models\Contact::emailStatuses()[$status] ?? $status }}
                                    </span>
                                    @if ($status === \App\Models\Contact::EMAIL_RISKY)
                                        <span class="block text-xs text-gray-500 dark:text-gray-300 mt-1">catch-all domain, not sendable</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-xs font-mono">{{ $contact->source }}</td>
                                <td class="px-6 py-3">{{ $contact->enrollments_count }}</td>
                                <td class="px-6 py-3 text-xs text-gray-500 dark:text-gray-300">{{ $contact->created_at->timezone(config('outreach.timezone'))->format('j M Y') }}</td>
                            </tr>
                            @if ($expanded && $expandedId === $contact->id)
                                <tr wire:key="contact-detail-{{ $contact->id }}">
                                    <td colspan="12" class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40">
                                        <div class="space-y-3 text-sm">
                                            <div class="flex items-center justify-between">
                                                <p class="font-semibold">Enrollments &amp; messages</p>
                                                <div class="flex items-center gap-4">
                                                <button wire:click="recheck({{ $contact->id }})"
                                                    wire:confirm="Send this lead back through the waterfall? Every provider it reaches is paid for again, so this is worth doing after adding or reordering one, not as a matter of routine."
                                                    class="text-xs text-gray-600 dark:text-gray-300 hover:underline">Check the address again</button>
                                                {{-- What this button can do depends on whether there is an
                                                     address. The opt-out list is keyed on one, so somebody we
                                                     can only name is never on it; offering "Suppress contact"
                                                     there is a button that appears to do nothing when pressed
                                                     a second time. What it can still do is stop their
                                                     sequences, so that is what it says. --}}
                                                @php($isSuppressed = filled($contact->email) && $suppressedEmails->has($contact->email))
                                                @php($openEnrollments = $expanded->enrollments->whereIn('status', \App\Models\Enrollment::openStatuses())->count())

                                                @if ($isSuppressed)
                                                    <button wire:click="unsuppress({{ $contact->id }})" class="text-xs text-green-700 dark:text-green-400 hover:underline">Remove suppression</button>
                                                @elseif (filled($contact->email))
                                                    <button wire:click="suppress({{ $contact->id }})"
                                                        wire:confirm="Suppress this contact? All open sequences stop and they can never be emailed again."
                                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Suppress contact</button>
                                                @elseif ($openEnrollments > 0)
                                                    <button wire:click="suppress({{ $contact->id }})"
                                                        wire:confirm="Stop every sequence this lead is in? There is no address to add to the opt-out list, so this stops what is running rather than blocking them for good."
                                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Stop all sequences</button>
                                                @else
                                                    <span class="text-xs text-gray-500 dark:text-gray-400" title="The opt-out list is keyed on an address, and this lead has none. Nothing is running for them either.">Nothing to stop</span>
                                                @endif
                                                </div>
                                            </div>

                                            {{-- Who this person is, beyond the columns the table has
                                                 room for. Job title and profile URL arrive from the
                                                 scraper and were not shown anywhere at all. --}}
                                            <div class="rounded border border-gray-200 dark:border-gray-700 p-3 grid sm:grid-cols-2 gap-x-8 gap-y-1 text-xs">
                                                <div class="flex gap-2">
                                                    <span class="text-gray-500 dark:text-gray-400">Job title</span>
                                                    <span>{{ $expanded->job_title ?: 'not known' }}</span>
                                                </div>
                                                <div class="flex gap-2">
                                                    <span class="text-gray-500 dark:text-gray-400">Company domain</span>
                                                    <span class="font-mono">{{ $expanded->domain ?: 'not known' }}</span>
                                                </div>
                                                <div class="flex gap-2">
                                                    <span class="text-gray-500 dark:text-gray-400">Profile</span>
                                                    @if ($expanded->profile_url)
                                                        <a href="{{ $expanded->profile_url }}" target="_blank" rel="noopener"
                                                            class="text-indigo-600 dark:text-indigo-400 hover:underline break-all">{{ $expanded->profile_url }}</a>
                                                    @else
                                                        <span>none</span>
                                                    @endif
                                                </div>
                                                <div class="flex gap-2">
                                                    {{-- Which finder supplied the address, which is the
                                                         only way to know who to hold answerable when it
                                                         bounces. --}}
                                                    <span class="text-gray-500 dark:text-gray-400">Address found by</span>
                                                    <span>
                                                        {{ $expanded->email_provider ?: 'nobody, it was supplied' }}
                                                        @if ($expanded->email_checked_at)
                                                            <span class="text-gray-500 dark:text-gray-400">
                                                                · checked {{ $expanded->email_checked_at->timezone(config('outreach.timezone'))->format('j M Y, H:i') }}
                                                            </span>
                                                        @else
                                                            <span class="text-gray-500 dark:text-gray-400">· never checked</span>
                                                        @endif
                                                    </span>
                                                </div>
                                            </div>

                                            @if ($expanded->lookups->isNotEmpty())
                                                <div class="rounded border border-gray-200 dark:border-gray-700 p-3">
                                                    <div class="flex items-center justify-between mb-2">
                                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">How the address was checked</p>
                                                        <a href="{{ route('settings.lookups', ['lead' => $expanded->email ?: $expanded->name]) }}"
                                                            class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline" wire:navigate>See these on the Lookups page</a>
                                                    </div>
                                                    <ul class="space-y-1">
                                                        @foreach ($expanded->lookups as $lookup)
                                                            <li class="text-xs text-gray-600 dark:text-gray-300">
                                                                {{-- One sentence, not a name glued to a label: a
                                                                     finder that returned an address did not say
                                                                     anything, it found something. --}}
                                                                <span class="font-medium">{{ $lookup->provider_name }}</span>{{ $lookup->verdictPhrase() }}
                                                                · {{ (float) $lookup->cost === 0.0 ? 'free' : '$'.number_format((float) $lookup->cost, 6) }}
                                                                · {{ $lookup->created_at->diffForHumans() }}
                                                                {{-- The provider's own words, read as a sentence.
                                                                     This was raw JSON, which nobody could read. --}}
                                                                @if ($lookup->explain())
                                                                    <span class="block text-gray-500 dark:text-gray-400">{{ $lookup->explain() }}</span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif

                                            {{-- Grouped by whoever sent it, which is how the column is
                                                 namespaced, rather than one unreadable line of JSON. --}}
                                            @if (! empty($expanded->extra))
                                                <div class="rounded border border-gray-200 dark:border-gray-700 p-3 space-y-2">
                                                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">What was sent with them</p>
                                                    @foreach ($expanded->extra as $source => $values)
                                                        <div class="text-xs">
                                                            {{-- The key is whichever system sent it, and on its
                                                                 own it reads as a stray value rather than as the
                                                                 name of whoever is speaking. --}}
                                                            <p class="text-gray-500 dark:text-gray-400">
                                                                Sent by <span class="font-mono">{{ $source }}</span>
                                                            </p>
                                                            @if (is_array($values))
                                                                <dl class="grid sm:grid-cols-2 gap-x-8">
                                                                    @foreach ($values as $key => $value)
                                                                        <div class="flex gap-2">
                                                                            <dt class="text-gray-500 dark:text-gray-400">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                                                                            <dd class="text-gray-700 dark:text-gray-200 break-all">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                                                        </div>
                                                                    @endforeach
                                                                </dl>
                                                            @else
                                                                <p class="text-gray-700 dark:text-gray-200">{{ $values }}</p>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @forelse ($expanded->enrollments as $enrollment)
                                                <div class="border dark:border-gray-700 rounded p-3">
                                                    <p>
                                                        <span class="font-mono text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-800">{{ $enrollment->automation->tag }}</span>
                                                        <span class="ml-2 text-xs">{{ $enrollment->statusLabel() }}</span>
                                                        @if ($enrollment->mailbox)
                                                            <span class="ml-2 text-xs text-gray-500 dark:text-gray-300">via {{ $enrollment->mailbox->email }}</span>
                                                        @endif
                                                        @if ($enrollment->stop_reason)
                                                            <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">({{ $enrollment->stop_reason }})</span>
                                                        @endif
                                                    </p>
                                                    <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
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
                                                <p class="text-gray-500 dark:text-gray-300">No enrollments.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="12" class="px-6 py-10 text-center text-gray-500 dark:text-gray-300">
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
