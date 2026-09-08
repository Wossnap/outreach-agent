<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Jobs\EnrichContact;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Ingest\ContactIngestService;
use App\Services\Sending\VerifiedEmailSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function apiHeaders(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['write'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_rejects_missing_api_key(): void
    {
        $response = $this->postJson('/api/contacts', ['email' => 'a@b.com', 'tags' => ['x']]);

        $response->assertStatus(401)->assertJson(['success' => false]);
    }

    public function test_rejects_invalid_api_key(): void
    {
        $response = $this->postJson('/api/contacts', ['email' => 'a@b.com', 'tags' => ['x']], [
            'Authorization' => 'Bearer nonsense',
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_key_without_ingest_ability(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['other'])->plainTextToken;

        $response = $this->postJson('/api/contacts', ['email' => 'a@b.com', 'tags' => ['x']], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_validates_payload(): void
    {
        $response = $this->postJson('/api/contacts', ['email' => 'not-an-email'], $this->apiHeaders());

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_ingests_contact_and_enrolls_by_tag(): void
    {
        Queue::fake();
        $automation = Automation::factory()->create(['tag' => 'seo-backlinks']);

        $response = $this->postJson('/api/contacts', [
            'email' => 'Jane@Example.com',
            'name' => 'Jane Doe',
            'company' => 'Acme',
            'extra' => ['niche' => 'gardening'],
            'tags' => ['seo-backlinks'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('success', true);

        $contact = Contact::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane Doe', $contact->name);
        // Filed under the source that sent it, so two systems can both report
        // a "niche" without overwriting each other.
        $this->assertSame(['api' => ['niche' => 'gardening']], $contact->extra);

        /*
         * Enrolled, but not started. Nothing has confirmed this address yet,
         * and by default nothing is sent to an address nobody has checked. The
         * enrollment is real and holds their place; the waterfall releases it
         * the moment an address is confirmed.
         */
        $enrollment = Enrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame($automation->id, $enrollment->automation_id);
        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $enrollment->status);
        $this->assertNull($enrollment->mailbox_id);

        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_drafts_at_once_when_an_address_need_not_be_confirmed(): void
    {
        // With the switch off, the address the caller supplied is trusted and
        // drafting starts at once.
        VerifiedEmailSwitch::turnOff();

        Queue::fake();
        $automation = Automation::factory()->create(['tag' => 'seo-backlinks']);

        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
            'tags' => ['seo-backlinks'],
        ], $this->apiHeaders())->assertOk();

        $enrollment = Enrollment::query()->firstOrFail();
        $this->assertSame($automation->id, $enrollment->automation_id);
        $this->assertSame(Enrollment::STATUS_ACTIVE, $enrollment->status);

        Queue::assertPushed(DraftEmailJob::class, fn ($job) => $job->enrollmentId === $enrollment->id && $job->stepPosition === 1);
    }

    public function test_upserts_existing_contact_merging_fields(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'pitch']);
        Contact::factory()->create(['email' => 'jane@example.com', 'name' => 'Jane', 'company' => null, 'extra' => ['api' => ['a' => 1]]]);

        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'company' => 'Acme',
            'extra' => ['b' => 2],
            'tags' => ['pitch'],
        ], $this->apiHeaders())->assertOk();

        $contact = Contact::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane', $contact->name);
        $this->assertSame('Acme', $contact->company);
        $this->assertSame(['api' => ['a' => 1, 'b' => 2]], $contact->extra);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_skips_tag_with_active_enrollment(): void
    {
        Queue::fake();
        $automation = Automation::factory()->create(['tag' => 'collab']);
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'automation_id' => $automation->id,
            'status' => Enrollment::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'tags' => ['collab'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.contacts.0.enrollments.0.skipped', 'already_active');
        $this->assertSame(1, Enrollment::query()->count());
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_re_enrolls_when_previous_enrollment_finished(): void
    {
        Queue::fake();
        $automation = Automation::factory()->create(['tag' => 'collab']);
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'automation_id' => $automation->id,
            'status' => Enrollment::STATUS_COMPLETED,
        ]);

        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'tags' => ['collab'],
        ], $this->apiHeaders())->assertOk();

        $this->assertSame(2, Enrollment::query()->count());
    }

    public function test_suppressed_contact_is_stored_but_never_enrolled(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'pitch']);
        Suppression::suppress('jane@example.com', Suppression::REASON_UNSUBSCRIBED);

        $response = $this->postJson('/api/contacts', [
            'email' => 'Jane@example.com',
            'tags' => ['pitch'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.contacts.0.skipped_reason', 'suppressed');

        // Stored, because forgetting somebody we already know just means the
        // next push invents them again. What suppression forbids is emailing
        // them, and that is what does not happen.
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_unknown_tags_reported_not_errored(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'pitch']);

        $response = $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'tags' => ['pitch', 'no-such-tag'],
        ], $this->apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.contacts.0.unknown_tags.0', 'no-such-tag');
        $this->assertSame(1, Enrollment::query()->count());
    }

    public function test_inactive_automation_counts_as_unknown(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'paused-tag', 'active' => false]);

        $response = $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'tags' => ['paused-tag'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.contacts.0.unknown_tags.0', 'paused-tag');
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_contact_can_be_re_added_after_their_draft_was_rejected(): void
    {
        Queue::fake();
        $automation = Automation::factory()->create(['tag' => 'collab']);
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);

        // Rejecting a draft stops the enrollment, so the contact does not
        // count as enrolled and another app can add them again.
        Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'automation_id' => $automation->id,
            'status' => Enrollment::STATUS_STOPPED_REJECTED,
        ]);

        $response = $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'tags' => ['collab'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.contacts.0.enrollments.0.skipped', null);
        $this->assertSame(2, Enrollment::query()->count());
        Queue::assertPushed(DraftEmailJob::class);
    }

    /**
     * The lookup is queued after the transaction closes, never inside it.
     *
     * Only one lookup per contact may be waiting at a time, and the queue
     * enforces that by inserting a lock row and letting the insert fail if one
     * is already there. Postgres abandons an entire transaction the moment any
     * statement in it fails, so taking that lock inside the batch transaction
     * would fail the whole batch whenever one person was pushed again while
     * their first lookup was still queued. Re-sending safely is the one thing
     * a batch promises.
     *
     * Asserted as a property - nothing is dispatched until commit - rather than
     * by reproducing the crash, because the assertion holds on any database and
     * says plainly what the rule is.
     *
     * A job's own afterCommit() does NOT satisfy this: it defers the dispatch
     * but takes the lock immediately.
     */
    public function test_the_lookup_is_queued_only_once_the_transaction_has_closed(): void
    {
        Queue::fake();

        DB::beginTransaction();

        app(ContactIngestService::class)->ingest([
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        Queue::assertNotPushed(EnrichContact::class);

        DB::commit();

        Queue::assertPushed(EnrichContact::class);
    }

    /** With no transaction open there is nothing to wait for. */
    public function test_the_lookup_is_queued_at_once_when_nothing_is_open(): void
    {
        Queue::fake();

        app(ContactIngestService::class)->ingest([
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        Queue::assertPushed(EnrichContact::class);
    }
}
