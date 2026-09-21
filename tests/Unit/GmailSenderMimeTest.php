<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Services\Gmail\GmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_an_untracked_message_is_plain_text_only(): void
    {
        // No token, no pixel, no HTML: byte for byte what was always sent.
        $mime = app(GmailSender::class)->buildMime($this->makeMessage(), '<x@outreach.test>');

        $this->assertStringContainsString("MIME-Version: 1.0\r\n", $mime);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        $this->assertStringNotContainsString('multipart', $mime);
        $this->assertStringNotContainsString('text/html', $mime);
    }

    public function test_a_tracked_message_carries_the_same_text_and_an_html_twin_with_the_pixel(): void
    {
        $message = $this->makeMessage(['open_token' => 'tok'.str_repeat('a', 37)]);

        $mime = app(GmailSender::class)->buildMime($message, '<x@outreach.test>');

        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="alt-', $mime);
        preg_match('/boundary="(alt-[^"]+)"/', $mime, $m);
        $parts = explode('--'.$m[1], $mime);

        // Text first, HTML last: a client shows the last alternative it can.
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $parts[1]);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $parts[2]);

        [, $textBody] = explode("\r\n\r\n", trim($parts[1]), 2);
        [, $htmlBody] = explode("\r\n\r\n", trim($parts[2]), 2);
        $html = base64_decode($htmlBody);

        $this->assertStringContainsString('Loved your latest post.', base64_decode($textBody));
        $this->assertStringContainsString('Loved your latest post.<br>', $html);
        $this->assertStringContainsString('<img src="'.route('track.open', ['token' => $message->open_token]).'"', $html);
        $this->assertStringContainsString('/t/o/'.$message->open_token.'.gif', $html);
    }

    public function test_the_tracking_switch_off_ignores_a_token(): void
    {
        config(['outreach.open_tracking.enabled' => false]);
        $message = $this->makeMessage(['open_token' => 'tok'.str_repeat('b', 37)]);

        $mime = app(GmailSender::class)->buildMime($message, '<x@outreach.test>');

        $this->assertStringNotContainsString('text/html', $mime);
        $this->assertStringNotContainsString($message->open_token, $mime);
    }

    public function test_the_html_twin_escapes_markup_and_links_bare_urls(): void
    {
        $html = app(GmailSender::class)->htmlBody(
            "See <b>this</b>: https://x.test/a?b=1&c=2.\nThen (https://y.test/p).",
            'https://app.test/t/o/abc.gif',
        );

        $this->assertStringContainsString('&lt;b&gt;this&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        // The full stop and the bracket belong to the sentence, not the link.
        $this->assertStringContainsString('<a href="https://x.test/a?b=1&amp;c=2">https://x.test/a?b=1&amp;c=2</a>.<br>', $html);
        $this->assertStringContainsString('(<a href="https://y.test/p">https://y.test/p</a>).', $html);
    }

    public function test_an_attachment_nests_the_alternative_inside_mixed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('attachments/brief.pdf', '%PDF-1.4 test');

        $message = $this->makeMessage(['open_token' => 'tok'.str_repeat('c', 37)]);
        $message->sequenceStep->update(['attachments' => [[
            'id' => 'att1', 'disk' => 'local', 'path' => 'attachments/brief.pdf',
            'filename' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 13,
        ]]]);

        $mime = app(GmailSender::class)->buildMime($message->fresh(['sequenceStep']), '<x@outreach.test>');

        $this->assertStringContainsString('Content-Type: multipart/mixed; boundary="outreach-', $mime);
        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="alt-', $mime);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $mime);
        $this->assertStringContainsString('filename="brief.pdf"', $mime);
        // The outer boundary opens before the inner one does.
        $this->assertLessThan(strpos($mime, 'boundary="alt-'), strpos($mime, 'boundary="outreach-'));
    }

    public function test_non_ascii_headers_are_encoded(): void
    {
        $message = $this->makeMessage(['subject' => 'Idea for Örebro ☀']);
        $mime = app(GmailSender::class)->buildMime($message, '<x@outreach.test>');

        $this->assertStringContainsString('Subject: =?UTF-8?B?', $mime);
    }
}
