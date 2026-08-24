<?php

namespace Tests\Feature;

use App\Livewire\ApprovalQueue;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_queue_is_ordered_by_created_at_then_id(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makePending();

        // created_at is second-precision. Postgres returns tied rows in heap
        // order, so an updated row comes back last and appears to jump to the
        // bottom of the queue. The id tiebreaker is what prevents that, and
        // SQLite cannot reproduce the reordering — so assert the ordering
        // itself rather than the symptom.
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        Livewire::test(ApprovalQueue::class);

        $select = collect($queries)->first(
            fn (string $sql) => str_contains($sql, 'from "messages"') && str_contains($sql, 'order by')
        );

        $this->assertNotNull($select, 'No ordered select against messages was run.');
        $this->assertStringContainsString('order by "created_at" asc, "id" asc', $select);
    }

    public function test_follow_up_subject_is_shown_read_only_with_the_thread_subject(): void
    {
        $this->actingAs(User::factory()->create());

        $mailbox = Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id]);

        $first = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        $second = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 2]);

        Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $first->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'status' => Message::STATUS_SENT,
            'subject' => 'The original subject',
            'sent_at' => now()->subDay(),
        ]);

        Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $second->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'A subject that will never be sent',
        ]);

        Livewire::test(ApprovalQueue::class)
            ->assertSee('Re: The original subject')
            ->assertSee('so they stay in the same conversation')
            ->assertDontSee('A subject that will never be sent');
    }

    public function test_rejecting_takes_the_contact_out_of_the_sequence(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        Livewire::test(ApprovalQueue::class)
            ->call('startReject', $message->id)
            ->set('rejectionNote', 'Not a good fit')
            ->call('confirmReject');

        $message->refresh();
        $enrollment = $message->enrollment->refresh();

        $this->assertSame(Message::STATUS_REJECTED, $message->status);
        $this->assertSame(Enrollment::STATUS_STOPPED_REJECTED, $enrollment->status);
        $this->assertNotNull($enrollment->stopped_at);
        $this->assertStringContainsString('Not a good fit', $enrollment->stop_reason);
    }

    public function test_rejecting_cancels_other_queued_emails_for_that_contact(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makePending();

        $laterStep = SequenceStep::factory()->create([
            'automation_id' => $message->enrollment->automation_id,
            'position' => 2,
        ]);
        $queued = Message::factory()->pendingApproval()->create([
            'enrollment_id' => $message->enrollment_id,
            'sequence_step_id' => $laterStep->id,
            'contact_id' => $message->contact_id,
            'mailbox_id' => $message->mailbox_id,
        ]);

        Livewire::test(ApprovalQueue::class)
            ->call('startReject', $message->id)
            ->call('confirmReject');

        $this->assertSame(Message::STATUS_CANCELLED, $queued->refresh()->status);
    }
}
