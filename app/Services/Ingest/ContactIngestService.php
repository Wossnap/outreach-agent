<?php

namespace App\Services\Ingest;

use App\Jobs\DraftEmailJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Services\Sending\MailboxSelector;
use Illuminate\Support\Facades\DB;

class ContactIngestService
{
    public function __construct(
        protected MailboxSelector $mailboxSelector,
    ) {}

    /**
     * Ingest a contact + tags from an external app. Upserts the contact,
     * creates one enrollment per known tag (skipping tags with an already
     * active enrollment), and kicks off drafting of step 1.
     *
     * @param  array{email: string, name?: ?string, company?: ?string, website?: ?string, custom?: ?array, source?: ?string, tags: array<string>}  $payload
     */
    public function ingest(array $payload): array
    {
        $email = mb_strtolower(trim($payload['email']));

        if (Suppression::isSuppressed($email)) {
            return [
                'contact_id' => null,
                'skipped_reason' => 'suppressed',
                'enrollments' => [],
                'unknown_tags' => [],
            ];
        }

        $contact = $this->upsertContact($email, $payload);

        $enrollments = [];
        $unknownTags = [];

        foreach (array_unique($payload['tags']) as $tag) {
            $automation = Automation::query()->where('tag', $tag)->where('active', true)->first();

            if (! $automation) {
                $unknownTags[] = $tag;

                continue;
            }

            $enrollments[] = $this->enroll($contact, $automation);
        }

        return [
            'contact_id' => $contact->id,
            'enrollments' => $enrollments,
            'unknown_tags' => $unknownTags,
        ];
    }

    protected function upsertContact(string $email, array $payload): Contact
    {
        $contact = Contact::query()->firstOrNew(['email' => $email]);

        foreach (['name', 'company', 'website', 'source'] as $field) {
            if (! empty($payload[$field])) {
                $contact->{$field} = $payload[$field];
            }
        }

        if (! empty($payload['custom']) && is_array($payload['custom'])) {
            $contact->custom = array_replace($contact->custom ?? [], $payload['custom']);
        }

        $contact->save();

        return $contact;
    }

    protected function enroll(Contact $contact, Automation $automation): array
    {
        $existing = Enrollment::query()
            ->where('contact_id', $contact->id)
            ->where('automation_id', $automation->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->exists();

        if ($existing) {
            return [
                'automation' => $automation->tag,
                'enrollment_id' => null,
                'skipped' => 'already_active',
            ];
        }

        $enrollment = DB::transaction(function () use ($contact, $automation) {
            return Enrollment::query()->create([
                'contact_id' => $contact->id,
                'automation_id' => $automation->id,
                'mailbox_id' => $this->mailboxSelector->select()?->id,
                'status' => Enrollment::STATUS_ACTIVE,
                'current_step' => 0,
            ]);
        });

        DraftEmailJob::dispatch($enrollment->id, 1);

        return [
            'automation' => $automation->tag,
            'enrollment_id' => $enrollment->id,
            'skipped' => null,
        ];
    }
}
