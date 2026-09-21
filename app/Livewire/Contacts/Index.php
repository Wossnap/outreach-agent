<?php

namespace App\Livewire\Contacts;

use App\Livewire\Concerns\WithIndexTable;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Services\Enrichment\Recheck;
use App\Services\Sending\EnrollmentStopper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithIndexTable, WithPagination;

    /** @var array<string> */
    public array $sortable = ['email', 'name', 'company', 'role', 'category', 'niche', 'source', 'email_status', 'created_at'];

    public string $defaultSort = 'created_at';

    #[Url]
    public string $email = '';

    #[Url]
    public string $name = '';

    #[Url]
    public string $company = '';

    /** @var array<string> */
    #[Url]
    public array $sources = [];

    /** @var array<string> */
    #[Url]
    public array $enrollmentStatuses = [];

    /** @var array<string> */
    #[Url]
    public array $emailStatuses = [];

    /** @var array<string> */
    #[Url]
    public array $roles = [];

    /** @var array<string> */
    #[Url]
    public array $categories = [];

    /** @var array<string> */
    #[Url]
    public array $niches = [];

    /**
     * Leads the waterfall could not find an address for are left out unless
     * this is on, or "Not found" is picked in the Address filter outright.
     * They are the one kind of lead nothing can be done with from here, and
     * on a page for choosing who to write to they were most of the noise.
     */
    #[Url]
    public bool $includeNotFound = false;

    #[Url]
    public string $suppressed = '';

    #[Url]
    public string $createdFrom = '';

    #[Url]
    public string $createdTo = '';

    public ?int $expandedId = null;

    /**
     * The leads ticked for a bulk action, by id.
     *
     * Kept as strings because that is what a checkbox posts, and comparing them
     * to integers anywhere would silently tick nothing.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    public ?string $flash = null;

    /**
     * The filters start open. This page is used by picking a slice of the
     * leads, so the controls for that were being opened on every visit.
     */
    public function mount(): void
    {
        $this->showFilters = true;
    }

    /**
     * Whether leads with no address found are left out of the list.
     *
     * Picking "Not found" in the Address filter is asking for them by name, so
     * it wins over the switch without anybody having to find the switch.
     */
    public function hidesNotFound(): bool
    {
        return ! $this->includeNotFound && ! in_array(Contact::EMAIL_NOT_FOUND, $this->emailStatuses, true);
    }

    protected function filterProperties(): array
    {
        return ['email', 'name', 'company', 'sources', 'emailStatuses', 'roles', 'categories', 'niches', 'includeNotFound', 'enrollmentStatuses', 'suppressed', 'createdFrom', 'createdTo'];
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    /**
     * Never email this person again, and stop whatever is under way.
     *
     * Both halves matter, and they are separate facts. The list is keyed on the
     * address, so a lead we can only name has nothing to add to it; their
     * enrollments are still stopped, which is the part being asked for. The
     * screen says which of the two happened.
     *
     * Waiting enrollments are included. They are as live as active ones - they
     * are why the person cannot be enrolled twice - and leaving them behind
     * meant somebody suppressed today started sending the day an address
     * arrived.
     */
    public function suppress(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);
        $recorded = Suppression::suppress($contact->email, Suppression::REASON_MANUAL);

        $stopped = 0;

        foreach ($contact->enrollments()->whereIn('status', Enrollment::openStatuses())->get() as $enrollment) {
            app(EnrollmentStopper::class)
                ->stop($enrollment, Enrollment::STATUS_STOPPED_SUPPRESSED, 'Suppressed manually');
            $stopped++;
        }

        if (! $recorded) {
            $this->flash = trim(($contact->name ?: 'That lead').' has no address to add to the opt-out list. '
                .($stopped > 0 ? "Their {$stopped} sequence(s) have been stopped." : 'Nothing was running for them.'));
        }
    }

    public function unsuppress(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);
        Suppression::query()->where('email', $contact->email)->delete();
    }

    /**
     * Send one lead back through the waterfall from the start.
     *
     * A lead written off as not found, or left risky because nothing would
     * confirm it, is never revisited on its own: an answer is only paid for
     * once. This is how it gets another go after a provider is added, given a
     * key, or reordered. Every provider it reaches is paid for again.
     */
    public function recheck(int $id, Recheck $recheck): void
    {
        $contact = Contact::query()->findOrFail($id);
        $outcome = $recheck->these(collect([$contact]));

        // Named, on a page where every other row looks the same. The bulk
        // version cannot do this, which is why the two messages differ.
        $this->flash = match (true) {
            $outcome->enrichmentOff => 'Enrichment is switched off, so nothing would be looked up. Turn it on under Waterfall.',
            $outcome->skippedSuppressed > 0 => 'That lead has opted out, so looking up their address would be spending money on somebody we may never email.',
            // Bracketed, because without it the sentence only appeared for a
            // lead that had no address: "??" binds tighter than "." and the
            // screen showed a bare email address where an explanation should
            // have been.
            default => ($contact->email ?? $contact->name ?: 'That lead').' is queued. The status moves as each provider answers.',
        };
    }

    /**
     * Send everything ticked back through the waterfall.
     *
     * The reason a selection exists at all: after adding a provider or giving
     * one a key, every lead already written off should get another go, and
     * doing that one row at a time is not a thing anybody will finish.
     *
     * Costs real money at every provider each lead reaches, so it is confirmed
     * with the count named, and the message afterwards says exactly what was
     * left out.
     */
    public function recheckSelected(Recheck $recheck): void
    {
        $contacts = Contact::query()->whereIn('id', $this->selected)->get();

        $this->flash = $recheck->these($contacts)->describe();
        $this->selected = [];
    }

    /**
     * Tick or clear every lead on the page being looked at, and no others.
     *
     * Deliberately the page rather than the whole filtered set. A tick box that
     * quietly selects four thousand leads behind the ones on screen is how
     * somebody spends a lot of money by accident.
     *
     * @param  array<int, int|string>  $ids  the leads currently drawn
     */
    public function toggleSelectPage(array $ids): void
    {
        $ids = array_map(strval(...), $ids);

        $everyOneAlreadyTicked = $ids !== [] && array_diff($ids, $this->selected) === [];

        $this->selected = $everyOneAlreadyTicked
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique(array_merge($this->selected, $ids)));
    }

    /**
     * Typeahead suggestions for the text filters.
     *
     * @return array<string>
     */
    public function suggestions(string $field, string $term): array
    {
        if (! in_array($field, ['email', 'name', 'company'], true) || mb_strlen($term) < 1) {
            return [];
        }

        return Contact::query()
            ->whereNotNull($field)
            // Case-insensitively, like the filter it feeds. The field name is
            // checked against a fixed list above, so it is safe to interpolate.
            ->whereRaw('lower('.$field.') like ?', ['%'.mb_strtolower($term).'%'])
            ->distinct()
            ->orderBy($field)
            ->limit(8)
            ->pluck($field)
            ->all();
    }

    public function render()
    {
        $query = Contact::query()
            ->withCount('enrollments')
            /*
             * Matched without regard to case, on both sides.
             *
             * Postgres LIKE is case-sensitive, so typing "acme" would not find
             * anybody at "Acme Ltd". Addresses are stored lower-cased and would
             * survive without this; names and companies are not.
             */
            ->when($this->email, fn ($q) => $q->whereRaw('lower(email) like ?', ['%'.mb_strtolower($this->email).'%']))
            ->when($this->name, fn ($q) => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->name).'%']))
            ->when($this->company, fn ($q) => $q->whereRaw('lower(company) like ?', ['%'.mb_strtolower($this->company).'%']))
            ->when($this->sources !== [], fn ($q) => $q->whereIn('source', $this->sources))
            ->when($this->emailStatuses !== [], fn ($q) => $q->whereIn('email_status', $this->emailStatuses))
            ->when($this->roles !== [], fn ($q) => $q->whereIn('role', $this->roles))
            ->when($this->categories !== [], fn ($q) => $q->whereIn('category', $this->categories))
            ->when($this->niches !== [], fn ($q) => $q->whereIn('niche', $this->niches))
            ->when($this->hidesNotFound(), fn ($q) => $q->where('email_status', '!=', Contact::EMAIL_NOT_FOUND))
            ->when($this->enrollmentStatuses !== [], fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->whereIn('status', $this->enrollmentStatuses)))
            ->when($this->suppressed === 'yes', fn ($q) => $q->whereIn('email', Suppression::query()->select('email')))
            /*
             * "Not suppressed" has to include everybody with no address at all.
             *
             * They cannot be on a list of addresses, so they are not
             * suppressed and they belong here. NOT IN is never true for a
             * null, so they have to be asked for separately or every lead the
             * scraper found is missing from the filter most likely to be used
             * to look for them.
             */
            ->when($this->suppressed === 'no', fn ($q) => $q->where(
                fn ($q) => $q->whereNull('email')->orWhereNotIn('email', Suppression::query()->select('email'))
            ))
            ->when($this->createdFrom, fn ($q) => $q->where('created_at', '>=', $this->createdFrom.' 00:00:00'))
            ->when($this->createdTo, fn ($q) => $q->where('created_at', '<=', $this->createdTo.' 23:59:59'));

        $contacts = $this->applySort($query)->paginate(25);

        $suppressedEmails = Suppression::query()
            ->whereIn('email', $contacts->getCollection()->pluck('email'))
            ->pluck('email')
            ->flip();

        $expanded = $this->expandedId
            ? Contact::query()->with(['enrollments.automation', 'enrollments.mailbox', 'messages.sequenceStep', 'lookups'])->find($this->expandedId)
            : null;

        return view('livewire.contacts.index', [
            'contacts' => $contacts,
            'suppressedEmails' => $suppressedEmails,
            'expanded' => $expanded,
            'availableSources' => $this->distinctValues('source'),
            'availableEmailStatuses' => Contact::emailStatuses(),
            'availableRoles' => $this->distinctValues('role'),
            'availableCategories' => $this->distinctValues('category'),
            'availableNiches' => $this->distinctValues('niche'),
            // Every status, worded as a person would say it. Waiting sits
            // beside active because it is the other open state: both mean the
            // person is in the sequence and it has not ended.
            'availableStatuses' => Enrollment::statusLabels(),
        ]);
    }

    /**
     * Every value a column currently holds, for a filter built from what is
     * there rather than from a fixed list.
     *
     * @return array<string, string>
     */
    protected function distinctValues(string $column): array
    {
        return Contact::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->mapWithKeys(fn (string $value) => [$value => $value])
            ->all();
    }
}
