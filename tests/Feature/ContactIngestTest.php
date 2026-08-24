<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function apiHeaders(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['ingest'])->plainTextToken;

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
            'custom' => ['niche' => 'gardening'],
            'tags' => ['seo-backlinks'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('success', true);

        $contact = Contact::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame(['niche' => 'gardening'], $contact->custom);

        $enrollment = Enrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame($automation->id, $enrollment->automation_id);
        $this->assertSame(Enrollment::STATUS_ACTIVE, $enrollment->status);

        Queue::assertPushed(DraftEmailJob::class, fn ($job) => $job->enrollmentId === $enrollment->id && $job->stepPosition === 1);
    }

    public function test_upserts_existing_contact_merging_fields(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'pitch']);
        Contact::factory()->create(['email' => 'jane@example.com', 'name' => 'Jane', 'company' => null, 'custom' => ['a' => 1]]);

        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'company' => 'Acme',
            'custom' => ['b' => 2],
            'tags' => ['pitch'],
        ], $this->apiHeaders())->assertOk();

        $contact = Contact::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane', $contact->name);
        $this->assertSame('Acme', $contact->company);
        $this->assertSame(['a' => 1, 'b' => 2], $contact->custom);
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

        $response->assertOk()->assertJsonPath('data.enrollments.0.skipped', 'already_active');
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

    public function test_suppressed_contact_is_skipped_entirely(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'pitch']);
        Suppression::suppress('jane@example.com', Suppression::REASON_UNSUBSCRIBED);

        $response = $this->postJson('/api/contacts', [
            'email' => 'Jane@example.com',
            'tags' => ['pitch'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.skipped_reason', 'suppressed');
        $this->assertSame(0, Contact::query()->count());
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
            ->assertJsonPath('data.unknown_tags.0', 'no-such-tag');
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

        $response->assertOk()->assertJsonPath('data.unknown_tags.0', 'paused-tag');
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

        // Rejecting a draft stops the enrollment, so the contact is no longer
        // counted as enrolled and another app can add them again.
        Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'automation_id' => $automation->id,
            'status' => Enrollment::STATUS_STOPPED_REJECTED,
        ]);

        $response = $this->postJson('/api/contacts', [
            'email' => 'jane@example.com',
            'tags' => ['collab'],
        ], $this->apiHeaders());

        $response->assertOk()->assertJsonPath('data.enrollments.0.skipped', null);
        $this->assertSame(2, Enrollment::query()->count());
        Queue::assertPushed(DraftEmailJob::class);
    }
}
