<?php

namespace App\Services\Ingest;

use App\Jobs\EnrichContact;
use App\Models\Automation;
use App\Models\Contact;
use App\Services\Enrichment\EnrichmentSwitch;
use App\Services\Sending\EnrollmentActivator;
use Illuminate\Support\Facades\DB;

class ContactIngestService
{
    public function __construct(
        protected EnrollmentActivator $activator,
    ) {}

    /**
     * Take a contact and their tags from another system.
     *
     * Upserts the person, enrolls them once per known tag, and starts the email
     * waterfall if we have no confirmed address for them.
     *
     * A contact does not have to arrive with an email: the people worth the
     * most here are often the ones we can only name. Identity is settled by
     * Contact::findOrNewFor, and the address may be found later.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ingest(array $payload): array
    {
        [$contact, $wasNew] = $this->upsertContact($payload);

        /*
         * Stored, but never enrolled.
         *
         * Refusing to store them as well would mean forgetting a person we
         * already know, and the next push would create them again from scratch.
         * What suppression forbids is emailing them, which is what is skipped.
         */
        if ($contact->isSuppressed()) {
            return [
                'contact_id' => $contact->id,
                'created' => $wasNew,
                'email_status' => $contact->email_status,
                'skipped_reason' => 'suppressed',
                'enrollments' => [],
                'unknown_tags' => [],
            ];
        }

        $enrollments = [];
        $unknownTags = [];

        foreach (array_unique($payload['tags'] ?? []) as $tag) {
            $automation = Automation::query()->where('tag', $tag)->where('active', true)->first();

            if (! $automation) {
                $unknownTags[] = $tag;

                continue;
            }

            $enrollments[] = $this->enroll($contact, $automation);
        }

        /*
         * Queued, never run here: a batch of five hundred would hold the
         * request open while several providers are called for each one.
         *
         * Nothing is queued while enrichment is switched off, because the job
         * would only turn round and do nothing. The contact stays pending,
         * which is the status meaning "we have not looked yet", and is picked
         * up by "check the address again" once somebody switches it on.
         *
         * Dispatch must happen through DB::afterCommit rather than the job's
         * own afterCommit(). EnrichContact is unique per contact, and the queue
         * enforces that by inserting a lock row and letting the insert fail if
         * one is already there. A job's afterCommit() defers the dispatch but
         * takes that lock immediately, inside this transaction, and Postgres
         * abandons an entire transaction the moment any statement in it fails.
         * So a person sent again while their first lookup is still queued would
         * fail the whole batch rather than being quietly skipped.
         */
        if (! $contact->isEmailResolved() && EnrichmentSwitch::isOn()) {
            $contactId = $contact->id;

            DB::afterCommit(fn () => EnrichContact::dispatch($contactId));
        }

        return [
            'contact_id' => $contact->id,
            'created' => $wasNew,
            'email_status' => $contact->email_status,
            'enrollments' => $enrollments,
            'unknown_tags' => $unknownTags,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Contact, 1: bool} the contact, and whether it is new
     */
    protected function upsertContact(array $payload): array
    {
        $contact = Contact::findOrNewFor(
            $payload['email'] ?? null,
            $payload['profile_url'] ?? null,
            $payload['name'] ?? null,
            $payload['domain'] ?? null,
        );

        $wasNew = ! $contact->exists;

        // Never overwrite a known value with a missing one. A second push
        // carrying only an address should not blank out the name an earlier
        // one supplied.
        foreach (['email', 'profile_url', 'company', 'job_title', 'role', 'category', 'niche', 'company_url'] as $field) {
            if (filled($payload[$field] ?? null)) {
                $contact->{$field} = $payload[$field];
            }
        }

        $contact->fill(Contact::resolveNameFields($payload, $contact->only(['name', 'first_name', 'last_name'])));

        /*
         * The company's domain, which is what a finder searches by.
         *
         * A contact with an address already has one, derived from it by the
         * model. One without an address has nothing to derive it from, and
         * without it no finder can be asked anything at all: the entire find
         * half of the waterfall is unreachable.
         *
         * One field takes both spellings: domainFrom reduces
         * "https://www.acme.example/about" to its host, so a caller holding a
         * website URL and one holding a bare domain send the same field.
         */
        $supplied = Contact::domainFrom($payload['domain'] ?? null);

        /*
         * What the caller said wins over what the address implies.
         *
         * Setting the email fills the domain in from the address, which is
         * right when the address is all we have and wrong the moment somebody
         * sends both: a person at Acme reachable on a personal address would be
         * recorded as working at gmail.com, and that domain is what the finders
         * search by and what the drafter writes about.
         */
        if (filled($supplied)) {
            $contact->domain = $supplied;
        }

        // Only the first source to claim somebody keeps it. Rewriting it on
        // every push loses the one fact that lets one source's leads be judged
        // against another's.
        $contact->source ??= filled($payload['source'] ?? null) ? $payload['source'] : Contact::SOURCE_API;

        // Filed under whoever sent it, so two systems can both report a
        // "score" and mean different things by it.
        if (! empty($payload['extra']) && is_array($payload['extra'])) {
            $contact->rememberExtra($contact->source, $payload['extra']);
        }

        $contact->save();

        return [$contact, $wasNew];
    }

    /**
     * @return array<string, mixed>
     */
    protected function enroll(Contact $contact, Automation $automation): array
    {
        if ($this->activator->hasOpenEnrollment($contact, $automation)) {
            return [
                'automation' => $automation->tag,
                'enrollment_id' => null,
                'status' => null,
                'skipped' => 'already_active',
            ];
        }

        $enrollment = $this->activator->enroll($contact, $automation);

        return [
            'automation' => $automation->tag,
            'enrollment_id' => $enrollment->id,
            // Says out loud that nothing is being drafted yet, and why, rather
            // than leaving a caller to wonder where their email went.
            'status' => $enrollment->status,
            'skipped' => null,
        ];
    }
}
