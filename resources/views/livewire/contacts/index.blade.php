<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Above the row, not inside it: as a flex child it pushed the
                 heading sideways every time a message appeared. --}}
            @if ($flash)
                <div class="rounded-md bg-band border border-rule p-3 text-sm text-ink">
                    {{ $flash }}
                </div>
            @endif

            <x-lookup-alerts />

            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">
                    Leads <span class="text-sm font-sans font-normal text-ink-dim">{{ $contacts->total() }}</span>
                </h2>
                <button wire:click="toggleFilters"
                    class="inline-flex items-center px-3 py-1.5 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition">
                    Filters
                    @if ($this->activeFilterCount() > 0)
                        <x-pill tone="good" class="ml-1">{{ $this->activeFilterCount() }}</x-pill>
                    @endif
                </button>
            </div>

            @if ($showFilters)
                <div class="bg-surface border border-rule sm:rounded-card p-6 grid sm:grid-cols-3 gap-4 text-sm">
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
                        <label class="mt-2 flex items-center gap-2 text-xs text-ink-dim">
                            <input type="checkbox" wire:model.live="includeNotFound" class="rounded border-rule-strong text-brand focus:ring-brand">
                            Include leads with no address found
                        </label>
                    </div>

                    <x-index.multi-select model="enrollmentStatuses" label="Enrollment status" :options="$availableStatuses" :selected="$enrollmentStatuses" placeholder="Any status" />

                    <div class="space-y-2">
                        <div>
                            <x-input-label value="Suppressed" />
                            <select wire:model.live="suppressed" class="mt-1 w-full rounded-md border-rule-strong bg-surface text-ink focus:border-brand focus:ring-brand text-sm">
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
                        <button wire:click="clearFilters" class="text-sm font-medium text-brand hover:underline">Clear all</button>
                    </div>
                </div>
            @endif

            {{-- Only when something is ticked. An action that costs money at six
                 providers should not sit on the page inviting a stray click. --}}
            @if (count($selected) > 0)
                <div class="flex flex-wrap items-center gap-3 p-3 rounded-md bg-band border border-rule text-ink">
                    <span class="text-sm text-ink">{{ count($selected) }} selected</span>
                    <button wire:click="recheckSelected"
                        wire:confirm="Send {{ count($selected) }} lead(s) back through the waterfall?&#10;&#10;Each one starts again from the first provider, and every provider it reaches is paid for again. This is worth doing after adding or reordering one, not as a matter of routine.&#10;&#10;Anybody who has opted out is skipped."
                        class="px-3 py-1.5 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover transition">
                        Check the addresses again
                    </button>
                    <button wire:click="$set('selected', [])" class="text-sm font-medium text-brand hover:underline">Clear the selection</button>
                </div>
            @endif

            <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="text-left">
                        <tr>
                            <th class="px-4 py-3 w-8">
                                @php($pageIds = $contacts->getCollection()->pluck('id')->all())
                                <input type="checkbox"
                                    wire:click="toggleSelectPage({{ json_encode($pageIds) }})"
                                    @checked($pageIds !== [] && array_diff(array_map(strval(...), $pageIds), $selected) === [])
                                    title="Select the leads on this page"
                                    class="rounded border-rule-strong text-brand focus:ring-brand">
                            </th>
                            {{-- The detail toggle sits here, beside the checkbox, because on a
                                 table this wide a column at the far right scrolls out of view. --}}
                            <th class="px-2 py-3 w-8"></th>
                            <x-index.sort-header field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Added</x-index.sort-header>
                            <x-index.sort-header field="email" :sort-field="$sortField" :sort-direction="$sortDirection">Email</x-index.sort-header>
                            <x-index.sort-header field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-index.sort-header>
                            <x-index.sort-header field="company" :sort-field="$sortField" :sort-direction="$sortDirection">Company</x-index.sort-header>
                            <x-index.sort-header field="job_title" :sort-field="$sortField" :sort-direction="$sortDirection">Job title</x-index.sort-header>
                            <x-index.sort-header field="category" :sort-field="$sortField" :sort-direction="$sortDirection">Category</x-index.sort-header>
                            <x-index.sort-header field="niche" :sort-field="$sortField" :sort-direction="$sortDirection">Niche</x-index.sort-header>
                            <x-index.sort-header field="email_status" :sort-field="$sortField" :sort-direction="$sortDirection">Address</x-index.sort-header>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Enrollments</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule text-ink">
                        @forelse ($contacts as $contact)
                            <tr wire:key="contact-{{ $contact->id }}" class="hover:bg-band transition {{ $suppressedEmails->has($contact->email) ? 'opacity-60' : '' }}">
                                <td class="px-4 py-3">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $contact->id }}"
                                        class="rounded border-rule-strong text-brand focus:ring-brand">
                                </td>
                                <td class="px-2 py-3">
                                    <button wire:click="toggleExpand({{ $contact->id }})"
                                        title="{{ $expandedId === $contact->id ? 'Hide detail' : 'Show detail' }}"
                                        aria-label="{{ $expandedId === $contact->id ? 'Hide detail' : 'Show detail' }}"
                                        class="text-ink-dim hover:text-ink transition">
                                        @if ($expandedId === $contact->id)
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                            </svg>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            </svg>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-xs text-ink-dim whitespace-nowrap">{{ $contact->created_at->timezone(config('outreach.timezone'))->format('j M Y') }}</td>
                                <td class="px-4 py-3">
                                    {{ $contact->email }}
                                    @if ($suppressedEmails->has($contact->email))
                                        <x-pill tone="danger" class="ml-1">suppressed</x-pill>
                                    @endif
                                </td>
                                {{-- The name opens the person's LinkedIn profile and the company
                                     its page, when either is on file. Plain text otherwise, so a
                                     link always goes somewhere. --}}
                                <td class="px-4 py-3">
                                    @if ($contact->name && $contact->profile_url)
                                        <a href="{{ $contact->profile_url }}" target="_blank" rel="noopener" class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink">{{ $contact->name }}</a>
                                    @else
                                        {{ $contact->name }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($contact->company && $contact->company_url)
                                        <a href="{{ $contact->company_url }}" target="_blank" rel="noopener" class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink">{{ $contact->company }}</a>
                                    @else
                                        {{ $contact->company }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $contact->job_title }}</td>
                                <td class="px-4 py-3">{{ $contact->category }}</td>
                                <td class="px-4 py-3">{{ $contact->niche }}</td>
                                <td class="px-4 py-3">
                                    @php($status = $contact->email_status)
                                    <x-pill :tone="match (true) {
                                        $status === \App\Models\Contact::EMAIL_VALID => 'good',
                                        in_array($status, [\App\Models\Contact::EMAIL_RISKY, \App\Models\Contact::EMAIL_WAITING], true) => 'warn',
                                        in_array($status, [\App\Models\Contact::EMAIL_INVALID, \App\Models\Contact::EMAIL_NOT_FOUND], true) => 'danger',
                                        default => 'neutral',
                                    }">
                                        {{ \App\Models\Contact::emailStatuses()[$status] ?? $status }}
                                    </x-pill>
                                    @if ($status === \App\Models\Contact::EMAIL_RISKY)
                                        <span class="block text-xs text-ink-dim mt-1">catch-all domain, not sendable</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($contact->enrollments as $enrollment)
                                            <x-pill>{{ $enrollment->automation->tag }}</x-pill>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                            @if ($expanded && $expandedId === $contact->id)
                                <tr wire:key="contact-detail-{{ $contact->id }}">
                                    <td colspan="11" class="px-4 py-4 bg-band">
                                        <div class="space-y-3 text-sm">
                                            <div class="flex items-center justify-between">
                                                <p class="font-semibold text-ink">Enrollments &amp; messages</p>
                                                <div class="flex items-center gap-4">
                                                <button wire:click="recheck({{ $contact->id }})"
                                                    wire:confirm="Send this lead back through the waterfall? Every provider it reaches is paid for again, so this is worth doing after adding or reordering one, not as a matter of routine."
                                                    class="text-xs font-medium text-brand hover:underline">Check the address again</button>
                                                {{-- What this button can do depends on whether there is an
                                                     address. The opt-out list is keyed on one, so somebody we
                                                     can only name is never on it; offering "Suppress contact"
                                                     there is a button that appears to do nothing when pressed
                                                     a second time. What it can still do is stop their
                                                     sequences, so that is what it says. --}}
                                                @php($isSuppressed = filled($contact->email) && $suppressedEmails->has($contact->email))
                                                @php($openEnrollments = $expanded->enrollments->whereIn('status', \App\Models\Enrollment::openStatuses())->count())

                                                @if ($isSuppressed)
                                                    <button wire:click="unsuppress({{ $contact->id }})" class="text-xs font-medium text-brand hover:underline">Remove suppression</button>
                                                @elseif (filled($contact->email))
                                                    <button wire:click="suppress({{ $contact->id }})"
                                                        wire:confirm="Suppress this contact? All open sequences stop and they can never be emailed again."
                                                        class="text-xs font-medium text-danger hover:underline">Suppress contact</button>
                                                @elseif ($openEnrollments > 0)
                                                    <button wire:click="suppress({{ $contact->id }})"
                                                        wire:confirm="Stop every sequence this lead is in? There is no address to add to the opt-out list, so this stops what is running rather than blocking them for good."
                                                        class="text-xs font-medium text-danger hover:underline">Stop all sequences</button>
                                                @else
                                                    <span class="text-xs text-ink-dim" title="The opt-out list is keyed on an address, and this lead has none. Nothing is running for them either.">Nothing to stop</span>
                                                @endif
                                                </div>
                                            </div>

                                            {{-- Who this person is, beyond the columns the table has
                                                 room for. Job title and profile URL arrive from the
                                                 scraper and were not shown anywhere at all. --}}
                                            <div class="rounded-md border border-rule bg-surface p-3 grid sm:grid-cols-2 gap-x-8 gap-y-1 text-xs">
                                                <div class="flex gap-2">
                                                    <span class="text-ink-dim">Job title</span>
                                                    <span>{{ $expanded->job_title ?: 'not known' }}</span>
                                                </div>
                                                <div class="flex gap-2">
                                                    <span class="text-ink-dim">Company domain</span>
                                                    <span>{{ $expanded->domain ?: 'not known' }}</span>
                                                </div>
                                                <div class="flex gap-2">
                                                    <span class="text-ink-dim">Profile</span>
                                                    @if ($expanded->profile_url)
                                                        <a href="{{ $expanded->profile_url }}" target="_blank" rel="noopener"
                                                            class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink break-all">{{ $expanded->profile_url }}</a>
                                                    @else
                                                        <span>none</span>
                                                    @endif
                                                </div>
                                                <div class="flex gap-2">
                                                    {{-- Which finder supplied the address, which is the
                                                         only way to know who to hold answerable when it
                                                         bounces. --}}
                                                    <span class="text-ink-dim">Address found by</span>
                                                    <span>
                                                        {{ $expanded->email_provider ?: 'nobody, it was supplied' }}
                                                        @if ($expanded->email_checked_at)
                                                            <span class="text-ink-dim">
                                                                · checked {{ $expanded->email_checked_at->timezone(config('outreach.timezone'))->format('j M Y, H:i') }}
                                                            </span>
                                                        @else
                                                            <span class="text-ink-dim">· never checked</span>
                                                        @endif
                                                    </span>
                                                </div>
                                            </div>

                                            @if ($expanded->lookups->isNotEmpty())
                                                <div class="rounded-md border border-rule bg-surface p-3">
                                                    <div class="flex items-center justify-between mb-2">
                                                        <p class="text-xs font-semibold uppercase tracking-label text-ink-dim">How the address was checked</p>
                                                        <a href="{{ route('settings.lookups', ['lead' => $expanded->email ?: $expanded->name]) }}"
                                                            class="text-xs font-medium text-brand hover:underline" wire:navigate>See these on the Lookups page</a>
                                                    </div>
                                                    <ul class="space-y-1">
                                                        @foreach ($expanded->lookups as $lookup)
                                                            <li class="text-xs text-ink-dim">
                                                                {{-- One sentence, not a name glued to a label: a
                                                                     finder that returned an address did not say
                                                                     anything, it found something. --}}
                                                                <span class="font-medium text-ink">{{ $lookup->provider_name }}</span>{{ $lookup->verdictPhrase() }}
                                                                · {{ (float) $lookup->cost === 0.0 ? 'free' : '$'.number_format((float) $lookup->cost, 6) }}
                                                                · {{ $lookup->created_at->diffForHumans() }}
                                                                {{-- The provider's own words, read as a sentence.
                                                                     This was raw JSON, which nobody could read. --}}
                                                                @if ($lookup->explain())
                                                                    <span class="block text-ink-dim">{{ $lookup->explain() }}</span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif

                                            {{-- Grouped by whoever sent it, which is how the column is
                                                 namespaced, rather than one unreadable line of JSON. --}}
                                            @if (! empty($expanded->extra))
                                                <div class="rounded-md border border-rule bg-surface p-3 space-y-2">
                                                    <p class="text-xs font-semibold uppercase tracking-label text-ink-dim">What was sent with them</p>
                                                    @foreach ($expanded->extra as $source => $values)
                                                        <div class="text-xs">
                                                            {{-- The key is whichever system sent it, and on its
                                                                 own it reads as a stray value rather than as the
                                                                 name of whoever is speaking. --}}
                                                            <p class="text-ink-dim">
                                                                Sent by <span class="font-medium text-ink">{{ $source }}</span>
                                                            </p>
                                                            @if (is_array($values))
                                                                <dl class="grid sm:grid-cols-2 gap-x-8">
                                                                    @foreach ($values as $key => $value)
                                                                        <div class="flex gap-2">
                                                                            <dt class="text-ink-dim">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                                                                            <dd class="text-ink break-all">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                                                        </div>
                                                                    @endforeach
                                                                </dl>
                                                            @else
                                                                <p class="text-ink">{{ $values }}</p>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @forelse ($expanded->enrollments as $enrollment)
                                                <div class="rounded-md border border-rule bg-surface p-3">
                                                    <p>
                                                        <x-pill>{{ $enrollment->automation->tag }}</x-pill>
                                                        <span class="ml-2 text-xs">{{ $enrollment->statusLabel() }}</span>
                                                        @if ($enrollment->mailbox)
                                                            <span class="ml-2 text-xs text-ink-dim">via {{ $enrollment->mailbox->email }}</span>
                                                        @endif
                                                        @if ($enrollment->stop_reason)
                                                            <span class="ml-2 text-xs text-ink-dim">({{ $enrollment->stop_reason }})</span>
                                                        @endif
                                                    </p>
                                                    <ul class="mt-2 space-y-1 text-xs text-ink-dim">
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
                                                <p class="text-ink-dim">No enrollments.</p>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-10 text-center text-ink-dim">
                                    @if ($this->activeFilterCount() > 0)
                                        No contacts match these filters. <button wire:click="clearFilters" class="font-medium text-brand hover:underline">Clear all</button>
                                    @else
                                        No contacts yet — they arrive via <code class="font-mono text-sm">POST /api/contacts</code> from your other apps.
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
