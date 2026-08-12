<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Services\Gmail\GmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailSenderMimeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeMessage(array $attributes = [], int $position = 1): Message
    {
        $mailbox = Mailbox::factory()->connected()->create(['display_name' => 'Sean Facer', 'email' => 'sean@outreach.test']);
        $contact = Contact::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $enrollment = Enrollment::factory()->create(['contact_id' => $contact->id, 'mailbox_id' => $mailbox->id]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => $position]);

        return Message::factory()->pendingApproval()->create(array_merge([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $contact->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Quick question about Example.com',
            'body_text' => "Hi Jane,\n\nLoved your latest post.\n\nSean",
        ], $attributes));
    }

    public function test_builds_first_email_headers(): void
    {
        $message = $this->makeMessage();
        $mime = app(GmailSender::class)->buildMime($message, '<abc123@outreach.test>');

        $this->assertStringContainsString('From: "Sean Facer" <sean@outreach.test>', $mime);
        $this->assertStringContainsString('To: "Jane Doe" <jane@example.com>', $mime);
        $this->assertStringContainsString('Subject: Quick question about Example.com', $mime);
        $this->assertStringContainsString('Message-ID: <abc123@outreach.test>', $mime);
        $this->assertStringNotContainsString('In-Reply-To', $mime);
        $this->assertStringContainsString('List-Unsubscribe: <mailto:sean@outreach.test?subject=unsubscribe>', $mime);

        // Body is base64 encoded after the blank line.
        [, $body] = explode("\r\n\r\n", $mime, 2);
        $this->assertStringContainsString('Loved your latest post.', base64_decode($body));
    }

    public function test_follow_up_threads_with_re_subject_and_reference_headers(): void
    {
        $followUp = $this->makeMessage(position: 2);

        $first = Message::factory()->sent()->create([
            'enrollment_id' => $followUp->enrollment_id,
            'sequence_step_id' => SequenceStep::factory()->create([
                'automation_id' => $followUp->enrollment->automation_id,
                'position' => 1,
            ])->id,
            'contact_id' => $followUp->contact_id,
            'mailbox_id' => $followUp->mailbox_id,
            'subject' => 'Quick question about Example.com',
            'rfc_message_id' => '<original@outreach.test>',
            'sent_at' => now()->subDays(3),
        ]);

        $mime = app(GmailSender::class)->buildMime($followUp, '<followup@outreach.test>');

        $this->assertStringContainsString('Subject: Re: Quick question about Example.com', $mime);
        $this->assertStringContainsString('In-Reply-To: <original@outreach.test>', $mime);
        $this->assertStringContainsString('References: <original@outreach.test>', $mime);
    }

    public function test_non_ascii_headers_are_encoded(): void
    {
        $message = $this->makeMessage(['subject' => 'Idea for Örebro ☀']);
        $mime = app(GmailSender::class)->buildMime($message, '<x@outreach.test>');

        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
    }
}
