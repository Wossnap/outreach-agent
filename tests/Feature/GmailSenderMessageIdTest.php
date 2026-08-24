<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Services\Gmail\GmailClientFactory;
use App\Services\Gmail\GmailSender;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;
use Google\Service\Gmail\Resource\UsersMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Gmail replaces the Message-ID we set with one of its own. Follow-ups quote
 * the stored value in In-Reply-To/References, so storing ours instead of
 * Gmail's points them at a message that does not exist and the recipient's
 * mail client files the follow-up as a separate conversation.
 */
class GmailSenderMessageIdTest extends TestCase
{
    use RefreshDatabase;

    protected function makeMessage(): Message
    {
        $mailbox = Mailbox::factory()->connected()->create(['email' => 'sender@outreach.test']);
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        $enrollment = Enrollment::factory()->create(['contact_id' => $contact->id, 'mailbox_id' => $mailbox->id]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        return Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $contact->id,
            'mailbox_id' => $mailbox->id,
            'rfc_message_id' => null,
        ]);
    }

    protected function fakeGmail(?string $assignedMessageId): void
    {
        $sent = new GmailMessage;
        $sent->setId('gmail-msg-1');
        $sent->setThreadId('gmail-thread-1');

        $fetched = new GmailMessage;
        $part = new MessagePart;

        if ($assignedMessageId !== null) {
            $header = new MessagePartHeader;
            $header->setName('Message-ID');
            $header->setValue($assignedMessageId);
            $part->setHeaders([$header]);
        } else {
            $part->setHeaders([]);
        }

        $fetched->setPayload($part);

        $resource = Mockery::mock(UsersMessages::class);
        $resource->shouldReceive('send')->once()->andReturn($sent);
        $resource->shouldReceive('get')->andReturn($fetched);

        $gmail = Mockery::mock(Gmail::class);
        $gmail->users_messages = $resource;

        $factory = Mockery::mock(GmailClientFactory::class);
        $factory->shouldReceive('gmailFor')->andReturn($gmail);

        $this->app->instance(GmailClientFactory::class, $factory);
    }

    public function test_stores_the_message_id_gmail_assigned(): void
    {
        $this->fakeGmail('<real-id-from-google@mail.gmail.com>');

        $result = app(GmailSender::class)->send($this->makeMessage());

        $this->assertSame('<real-id-from-google@mail.gmail.com>', $result['rfc_message_id']);
        $this->assertSame('gmail-msg-1', $result['gmail_message_id']);
        $this->assertSame('gmail-thread-1', $result['gmail_thread_id']);
    }

    public function test_falls_back_to_the_generated_id_when_gmail_returns_none(): void
    {
        $this->fakeGmail(null);

        $result = app(GmailSender::class)->send($this->makeMessage());

        $this->assertNotEmpty($result['rfc_message_id']);
        $this->assertStringEndsWith('@outreach.test>', $result['rfc_message_id']);
    }
}
