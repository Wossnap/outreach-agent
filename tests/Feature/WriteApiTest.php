<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WriteApiTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $token = User::factory()->create()->createToken('writer', ['write'])->plainTextToken;
        $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    protected function readHeaders(): array
    {
        $token = User::factory()->create()->createToken('reader', ['read'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_a_read_only_key_cannot_write(): void
    {
        $this->postJson('/api/automations', [
            'tag' => 'x', 'name' => 'X',
        ], $this->readHeaders())->assertStatus(403);
    }

    public function test_an_automation_can_be_created_with_its_steps(): void
    {
        $this->postJson('/api/automations', [
            'tag' => 'seo-backlinks',
            'name' => 'SEO backlinks',
            'steps' => [
                ['position' => 1, 'drafting_instructions' => 'Introduce us.'],
                ['position' => 2, 'delay_days' => 3, 'drafting_instructions' => 'Nudge.'],
            ],
        ], $this->headers)
            ->assertStatus(201)
            ->assertJsonPath('data.tag', 'seo-backlinks')
            ->assertJsonCount(2, 'data.steps');

        $this->assertDatabaseHas('automations', ['tag' => 'seo-backlinks']);
        $this->assertSame(2, SequenceStep::query()->count());
    }

    public function test_a_duplicate_tag_is_refused(): void
    {
        Automation::factory()->create(['tag' => 'taken']);

        $this->postJson('/api/automations', ['tag' => 'taken', 'name' => 'X'], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_two_steps_cannot_share_a_position(): void
    {
        $automation = Automation::factory()->create(['tag' => 'seo']);
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 1]);

        $this->postJson("/api/automations/{$automation->id}/steps", [
            'position' => 1, 'drafting_instructions' => 'Clash.',
        ], $this->headers)->assertStatus(422);
    }

    public function test_an_existing_contact_can_be_enrolled(): void
    {
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        Automation::factory()->create(['tag' => 'seo']);

        $this->postJson('/api/enrollments', [
            'email' => 'jane@example.com', 'tag' => 'seo',
        ], $this->headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', Enrollment::STATUS_ACTIVE);

        Queue::assertPushed(DraftEmailJob::class);
        $this->assertDatabaseHas('enrollments', ['contact_id' => $contact->id]);
    }

    public function test_a_suppressed_contact_cannot_be_enrolled(): void
    {
        Contact::factory()->create(['email' => 'gone@example.com']);
        Suppression::suppress('gone@example.com', Suppression::REASON_UNSUBSCRIBED);
        Automation::factory()->create(['tag' => 'seo']);

        $this->postJson('/api/enrollments', [
            'email' => 'gone@example.com', 'tag' => 'seo',
        ], $this->headers)->assertStatus(409);

        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_a_contact_cannot_be_enrolled_twice_in_the_same_automation(): void
    {
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_ACTIVE]);

        $this->postJson('/api/enrollments', [
            'contact_id' => $enrollment->contact_id,
            'automation_id' => $enrollment->automation_id,
        ], $this->headers)->assertStatus(409);
    }

    public function test_stopping_an_enrollment_cancels_its_queued_emails(): void
    {
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_ACTIVE]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        $message = Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
        ]);

        $this->deleteJson("/api/enrollments/{$enrollment->id}", [], $this->headers)->assertOk();

        $this->assertSame(Enrollment::STATUS_CANCELLED, $enrollment->fresh()->status);
        $this->assertSame(Message::STATUS_CANCELLED, $message->fresh()->status);
    }

    public function test_suppressing_an_address_also_stops_its_live_sequence(): void
    {
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        $enrollment = Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'status' => Enrollment::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/suppressions', ['email' => 'jane@example.com'], $this->headers)
            ->assertStatus(201);

        // Suppressing without stopping would leave already-drafted emails
        // queued behind it, so the address would still be written to.
        $this->assertSame(Enrollment::STATUS_STOPPED_SUPPRESSED, $enrollment->fresh()->status);
    }

    public function test_a_draft_can_be_edited_then_rejected(): void
    {
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_ACTIVE]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        $message = Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
        ]);

        $this->patchJson("/api/messages/{$message->id}", [
            'subject' => 'Rewritten',
        ], $this->headers)->assertOk()->assertJsonPath('data.subject', 'Rewritten');

        $this->postJson("/api/messages/{$message->id}/reject", [
            'note' => 'Off tone',
        ], $this->headers)->assertOk();

        $this->assertSame(Message::STATUS_REJECTED, $message->fresh()->status);
        // Rejecting takes the contact out of the sequence, the same as the
        // dashboard does, so the enrollment cannot sit active with nothing
        // left to advance it.
        $this->assertSame(Enrollment::STATUS_STOPPED_REJECTED, $enrollment->fresh()->status);
    }

    public function test_a_sent_email_cannot_be_edited(): void
    {
        $enrollment = Enrollment::factory()->create();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        $message = Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'status' => Message::STATUS_SENT,
        ]);

        $this->patchJson("/api/messages/{$message->id}", ['subject' => 'Too late'], $this->headers)
            ->assertStatus(409);
    }

    public function test_a_mailbox_can_be_paused_and_resumed(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();

        $this->postJson("/api/mailboxes/{$mailbox->id}/pause", ['reason' => 'Testing'], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.status', Mailbox::STATUS_PAUSED);

        $this->postJson("/api/mailboxes/{$mailbox->id}/resume", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.status', Mailbox::STATUS_ACTIVE);
    }

    public function test_a_disconnected_mailbox_cannot_be_resumed_over_the_api(): void
    {
        $mailbox = Mailbox::factory()->connected()->create(['status' => Mailbox::STATUS_DISCONNECTED]);

        // Its Google token is gone; only reconnecting in the dashboard fixes
        // that, and flipping the status here would just queue failing sends.
        $this->postJson("/api/mailboxes/{$mailbox->id}/resume", [], $this->headers)
            ->assertStatus(409);
    }
}
