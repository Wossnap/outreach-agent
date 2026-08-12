<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Services\Inbound\InboundEmail;
use App\Services\Inbound\InboundProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected Mailbox $mailbox;

    protected Enrollment $enrollment;

    protected Message $sent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->connected()->create();
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        $this->enrollment = Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'mailbox_id' => $this->mailbox->id,
            'gmail_thread_id' => 'thread-abc',
            'current_step' => 1,
        ]);
        $step = SequenceStep::factory()->create(['automation_id' => $this->enrollment->automation_id]);
        $this->sent = Message::factory()->sent()->create([
            'enrollment_id' => $this->enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $contact->id,
            'mailbox_id' => $this->mailbox->id,
            'gmail_thread_id' => 'thread-abc',
        ]);
    }

    protected function inbound(array $overrides = []): InboundEmail
    {
        return new InboundEmail(
            gmailMessageId: $overrides['id'] ?? 'in-1',
            gmailThreadId: $overrides['thread'] ?? 'thread-abc',
            fromEmail: $overrides['from'] ?? 'jane@example.com',
            subject: $overrides['subject'] ?? 'Re: hello',
            snippet: 'snippet',
            bodyText: $overrides['body'] ?? 'Interesting, tell me more.',
            headers: $overrides['headers'] ?? [],
            labelIds: $overrides['labels'] ?? ['INBOX'],
        );
    }

    public function test_reply_stops_enrollment_and_cancels_pending_messages(): void
    {
        $step2 = SequenceStep::factory()->create(['automation_id' => $this->enrollment->automation_id, 'position' => 2]);
        $pendingFollowUp = Message::factory()->scheduled()->create([
            'enrollment_id' => $this->enrollment->id,
            'sequence_step_id' => $step2->id,
            'contact_id' => $this->enrollment->contact_id,
            'mailbox_id' => $this->mailbox->id,
        ]);

        $reply = app(InboundProcessor::class)->process($this->mailbox, $this->inbound());

        $this->assertNotNull($reply);
        $this->assertSame(Reply::CLASS_REPLY, $reply->classification);
        $this->assertSame($this->enrollment->id, $reply->enrollment_id);
        $this->assertSame(Enrollment::STATUS_STOPPED_REPLY, $this->enrollment->fresh()->status);
        $this->assertSame(Message::STATUS_CANCELLED, $pendingFollowUp->fresh()->status);
        // The already-sent message is untouched.
        $this->assertSame(Message::STATUS_SENT, $this->sent->fresh()->status);
    }

    public function test_bounce_suppresses_and_stops(): void
    {
        $email = $this->inbound([
            'from' => 'mailer-daemon@googlemail.com',
            'body' => "Final-Recipient: rfc822; jane@example.com\nAction: failed",
        ]);

        app(InboundProcessor::class)->process($this->mailbox, $email);

        $this->assertTrue(Suppression::isSuppressed('jane@example.com'));
        $this->assertSame(Enrollment::STATUS_STOPPED_BOUNCE, $this->enrollment->fresh()->status);
    }

    public function test_unsubscribe_suppresses_and_stops(): void
    {
        $email = $this->inbound(['body' => 'Please remove me from your list.']);

        app(InboundProcessor::class)->process($this->mailbox, $email);

        $this->assertTrue(Suppression::isSuppressed('jane@example.com'));
        $this->assertSame(Enrollment::STATUS_STOPPED_UNSUBSCRIBE, $this->enrollment->fresh()->status);
    }

    public function test_auto_reply_recorded_but_sequence_continues(): void
    {
        $email = $this->inbound(['headers' => ['auto-submitted' => 'auto-replied'], 'subject' => 'Automatic reply: Re: hello']);

        $reply = app(InboundProcessor::class)->process($this->mailbox, $email);

        $this->assertSame(Reply::CLASS_AUTO_REPLY, $reply->classification);
        $this->assertSame(Enrollment::STATUS_ACTIVE, $this->enrollment->fresh()->status);
    }

    public function test_matches_by_sender_email_when_thread_unknown(): void
    {
        $email = $this->inbound(['thread' => 'some-new-thread']);

        $reply = app(InboundProcessor::class)->process($this->mailbox, $email);

        $this->assertSame($this->enrollment->id, $reply->enrollment_id);
        $this->assertSame(Enrollment::STATUS_STOPPED_REPLY, $this->enrollment->fresh()->status);
    }

    public function test_unrelated_mail_is_ignored(): void
    {
        $email = $this->inbound(['thread' => 'other-thread', 'from' => 'newsletter@random.com']);

        $reply = app(InboundProcessor::class)->process($this->mailbox, $email);

        $this->assertNull($reply);
        $this->assertSame(0, Reply::query()->count());
    }

    public function test_own_sent_mail_is_ignored(): void
    {
        $email = $this->inbound(['labels' => ['SENT']]);

        $this->assertNull(app(InboundProcessor::class)->process($this->mailbox, $email));
    }

    public function test_duplicate_gmail_message_id_is_ignored(): void
    {
        app(InboundProcessor::class)->process($this->mailbox, $this->inbound());
        $again = app(InboundProcessor::class)->process($this->mailbox, $this->inbound());

        $this->assertNull($again);
        $this->assertSame(1, Reply::query()->count());
    }
}
