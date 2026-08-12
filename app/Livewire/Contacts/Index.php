<?php

namespace App\Livewire\Contacts;

use App\Livewire\Concerns\WithIndexTable;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithIndexTable, WithPagination;

    /** @var array<string> */
    public array $sortable = ['email', 'name', 'company', 'source', 'created_at'];

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

    #[Url]
    public string $suppressed = '';

    #[Url]
    public string $createdFrom = '';

    #[Url]
    public string $createdTo = '';

    public ?int $expandedId = null;

    protected function filterProperties(): array
    {
        return ['email', 'name', 'company', 'sources', 'enrollmentStatuses', 'suppressed', 'createdFrom', 'createdTo'];
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function suppress(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);
        Suppression::suppress($contact->email, Suppression::REASON_MANUAL);

        foreach ($contact->enrollments()->where('status', Enrollment::STATUS_ACTIVE)->get() as $enrollment) {
            app(\App\Services\Sending\EnrollmentStopper::class)
                ->stop($enrollment, Enrollment::STATUS_STOPPED_SUPPRESSED, 'Suppressed manually');
        }
    }

    public function unsuppress(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);
        Suppression::query()->where('email', $contact->email)->delete();
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
            ->where($field, 'like', '%'.$term.'%')
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
            ->when($this->email, fn ($q) => $q->where('email', 'like', '%'.mb_strtolower($this->email).'%'))
            ->when($this->name, fn ($q) => $q->where('name', 'like', '%'.$this->name.'%'))
            ->when($this->company, fn ($q) => $q->where('company', 'like', '%'.$this->company.'%'))
            ->when($this->sources !== [], fn ($q) => $q->whereIn('source', $this->sources))
            ->when($this->enrollmentStatuses !== [], fn ($q) => $q->whereHas('enrollments', fn ($e) => $e->whereIn('status', $this->enrollmentStatuses)))
            ->when($this->suppressed === 'yes', fn ($q) => $q->whereIn('email', Suppression::query()->select('email')))
            ->when($this->suppressed === 'no', fn ($q) => $q->whereNotIn('email', Suppression::query()->select('email')))
            ->when($this->createdFrom, fn ($q) => $q->where('created_at', '>=', $this->createdFrom.' 00:00:00'))
            ->when($this->createdTo, fn ($q) => $q->where('created_at', '<=', $this->createdTo.' 23:59:59'));

        $contacts = $this->applySort($query)->paginate(25);

        $suppressedEmails = Suppression::query()
            ->whereIn('email', $contacts->getCollection()->pluck('email'))
            ->pluck('email')
            ->flip();

        $expanded = $this->expandedId
            ? Contact::query()->with(['enrollments.automation', 'enrollments.mailbox', 'messages.sequenceStep'])->find($this->expandedId)
            : null;

        return view('livewire.contacts.index', [
            'contacts' => $contacts,
            'suppressedEmails' => $suppressedEmails,
            'expanded' => $expanded,
            'availableSources' => Contact::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source'),
            'availableStatuses' => [
                Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_STOPPED_REPLY,
                Enrollment::STATUS_STOPPED_UNSUBSCRIBE, Enrollment::STATUS_STOPPED_BOUNCE,
                Enrollment::STATUS_STOPPED_SUPPRESSED, Enrollment::STATUS_CANCELLED, Enrollment::STATUS_FAILED,
            ],
        ]);
    }
}
