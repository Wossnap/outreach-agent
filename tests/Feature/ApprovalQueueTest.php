<?php

namespace Tests\Feature;

use App\Livewire\ApprovalQueue;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function makePending(?Mailbox $mailbox = null): Message
    {
        $mailbox ??= Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        return Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->get('/approvals')->assertRedirect('/login');
    }

    public function test_lists_pending_messages(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        Livewire::test(ApprovalQueue::class)
            ->assertSee($message->contact->email)
            ->assertSet('drafts.'.$message->id.'.subject', $message->subject);
    }

    public function test_approve_schedules_message(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        Livewire::test(ApprovalQueue::class)->call('approve', $message->id);

        $fresh = $message->fresh();
        $this->assertSame(Message::STATUS_SCHEDULED, $fresh->status);
        $this->assertNotNull($fresh->scheduled_at);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_inline_edit_persists_and_marks_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        Livewire::test(ApprovalQueue::class)
            ->set('drafts.'.$message->id.'.subject', 'A better subject')
            ->set('drafts.'.$message->id.'.body', 'A better body.');

        $fresh = $message->fresh();
        $this->assertSame('A better subject', $fresh->subject);
        $this->assertSame('A better body.', $fresh->body_text);
        $this->assertTrue($fresh->edited_by_user);
        $this->assertNotSame($fresh->ai_subject, $fresh->subject);
    }

    public function test_edits_survive_approval(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        Livewire::test(ApprovalQueue::class)
            ->set('drafts.'.$message->id.'.subject', 'Edited subject')
            ->call('approve', $message->id);

        $this->assertSame('Edited subject', $message->fresh()->subject);
    }

    public function test_reject_with_note(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        Livewire::test(ApprovalQueue::class)
            ->call('startReject', $message->id)
            ->set('rejectionNote', 'Too pushy')
            ->call('confirmReject');

        $fresh = $message->fresh();
        $this->assertSame(Message::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Too pushy', $fresh->rejection_note);
    }

    public function test_bulk_approve_only_selected(): void
    {
        $this->actingAs(User::factory()->create());
        $mailbox = Mailbox::factory()->connected()->create();
        $a = $this->makePending($mailbox);
        $b = $this->makePending($mailbox);
        $c = $this->makePending($mailbox);

        Livewire::test(ApprovalQueue::class)
            ->set('selected.'.$a->id, true)
            ->set('selected.'.$b->id, true)
            ->call('bulkApprove');

        $this->assertSame(Message::STATUS_SCHEDULED, $a->fresh()->status);
        $this->assertSame(Message::STATUS_SCHEDULED, $b->fresh()->status);
        $this->assertSame(Message::STATUS_PENDING_APPROVAL, $c->fresh()->status);

        // Bulk-approved slots on the same mailbox never collide.
        $this->assertNotSame(
            $a->fresh()->scheduled_at->toIso8601String(),
            $b->fresh()->scheduled_at->toIso8601String(),
        );
    }

    public function test_approve_without_mailbox_warns_and_stays_approved(): void
    {
        $this->actingAs(User::factory()->create());
        $enrollment = Enrollment::factory()->create(['mailbox_id' => null]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        $message = Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
        ]);

        Livewire::test(ApprovalQueue::class)->call('approve', $message->id);

        $this->assertSame(Message::STATUS_APPROVED, $message->fresh()->status);
        $this->assertNull($message->fresh()->scheduled_at);
    }
}
