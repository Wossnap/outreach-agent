<?php

namespace Tests\Feature;

use App\Livewire\ApprovalQueue;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\Mailboxes\Index as MailboxesIndex;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Approving while every mailbox is paused or disconnected leaves a message at
 * "approved" with no send slot. Nothing else reads that status, so without a
 * recovery pass the email is never sent, never queued and never reported as
 * failed.
 */
class ApprovedWithoutSlotTest extends TestCase
{
    use RefreshDatabase;

    protected function makeApprovedWithoutSlot(?Mailbox $mailbox = null): Message
    {
        $enrollment = Enrollment::factory()->create([
            'mailbox_id' => $mailbox?->id,
            'status' => Enrollment::STATUS_ACTIVE,
        ]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        return Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox?->id,
            'status' => Message::STATUS_APPROVED,
            'approved_at' => now(),
            'scheduled_at' => null,
            'sent_at' => null,
        ]);
    }

    public function test_reconciler_schedules_an_approved_message_once_a_mailbox_is_available(): void
    {
        $message = $this->makeApprovedWithoutSlot();

        Mailbox::factory()->connected()->create();

        $this->artisan('outreach:reconcile-stuck')->assertSuccessful();

        $message->refresh();

        $this->assertSame(Message::STATUS_SCHEDULED, $message->status);
        $this->assertNotNull($message->scheduled_at);
    }

    public function test_reconciler_leaves_it_alone_when_no_mailbox_can_send(): void
    {
        $message = $this->makeApprovedWithoutSlot();

        Mailbox::factory()->connected()->create(['status' => Mailbox::STATUS_PAUSED]);

        $this->artisan('outreach:reconcile-stuck')->assertSuccessful();

        $message->refresh();

        $this->assertSame(Message::STATUS_APPROVED, $message->status);
        $this->assertNull($message->scheduled_at);
    }

    public function test_reconciler_cancels_it_when_the_sequence_has_stopped(): void
    {
        $message = $this->makeApprovedWithoutSlot();
        $message->enrollment->update(['status' => Enrollment::STATUS_STOPPED_REPLY]);

        Mailbox::factory()->connected()->create();

        $this->artisan('outreach:reconcile-stuck')->assertSuccessful();

        $this->assertSame(Message::STATUS_CANCELLED, $message->refresh()->status);
    }

    public function test_waiting_messages_are_listed_on_the_approvals_page(): void
    {
        $this->actingAs(User::factory()->create());
        $message = $this->makeApprovedWithoutSlot();

        Livewire::test(ApprovalQueue::class)
            ->assertSee('Waiting to send')
            ->assertSee($message->contact->email);
    }

    public function test_the_contact_row_shows_when_an_email_will_send(): void
    {
        $this->actingAs(User::factory()->create());

        $message = $this->makeApprovedWithoutSlot();
        Mailbox::factory()->connected()->create();
        $this->artisan('outreach:reconcile-stuck')->assertSuccessful();

        $sendsAt = $message->refresh()->scheduled_at
            ->timezone(config('outreach.timezone'))
            ->format('j M H:i:s');

        Livewire::test(ContactsIndex::class)
            ->call('toggleExpand', $message->contact_id)
            ->assertSee('sending '.$sendsAt);
    }

    public function test_the_contact_row_shows_an_email_waiting_for_a_mailbox(): void
    {
        $this->actingAs(User::factory()->create());

        $message = $this->makeApprovedWithoutSlot();

        Livewire::test(ContactsIndex::class)
            ->call('toggleExpand', $message->contact_id)
            ->assertSee('waiting for a mailbox');
    }

    public function test_resuming_a_mailbox_schedules_emails_that_were_waiting(): void
    {
        $this->actingAs(User::factory()->create());

        $mailbox = Mailbox::factory()->connected()->create(['status' => Mailbox::STATUS_PAUSED]);
        $message = $this->makeApprovedWithoutSlot();

        Livewire::test(MailboxesIndex::class)->call('resume', $mailbox->id);

        $message->refresh();

        $this->assertSame(Message::STATUS_SCHEDULED, $message->status);
        $this->assertNotNull($message->scheduled_at);
    }

    public function test_resuming_an_auto_paused_mailbox_does_the_same(): void
    {
        $this->actingAs(User::factory()->create());

        $mailbox = Mailbox::factory()->connected()->create([
            'status' => Mailbox::STATUS_PAUSED,
            'paused_reason' => 'Auto-paused: domain is missing SPF or DKIM.',
        ]);
        $message = $this->makeApprovedWithoutSlot();

        Livewire::test(MailboxesIndex::class)->call('resume', $mailbox->id);

        $this->assertSame(Message::STATUS_SCHEDULED, $message->refresh()->status);
        $this->assertNull($mailbox->refresh()->paused_reason);
    }
}
