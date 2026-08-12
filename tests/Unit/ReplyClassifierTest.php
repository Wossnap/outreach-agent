<?php

namespace Tests\Unit;

use App\Models\Reply;
use App\Services\Inbound\InboundEmail;
use App\Services\Inbound\ReplyClassifier;
use Tests\TestCase;

class ReplyClassifierTest extends TestCase
{
    protected function email(array $overrides = []): InboundEmail
    {
        return new InboundEmail(
            gmailMessageId: $overrides['id'] ?? 'msg-1',
            gmailThreadId: 'thread-1',
            fromEmail: $overrides['from'] ?? 'jane@example.com',
            subject: $overrides['subject'] ?? 'Re: Quick question',
            snippet: '',
            bodyText: $overrides['body'] ?? 'Sounds interesting, tell me more!',
            headers: $overrides['headers'] ?? [],
        );
    }

    public function test_plain_reply(): void
    {
        $this->assertSame(Reply::CLASS_REPLY, app(ReplyClassifier::class)->classify($this->email()));
    }

    public function test_mailer_daemon_is_bounce(): void
    {
        $email = $this->email(['from' => 'mailer-daemon@googlemail.com', 'subject' => 'Delivery Status Notification (Failure)']);

        $this->assertSame(Reply::CLASS_BOUNCE, app(ReplyClassifier::class)->classify($email));
    }

    public function test_delivery_status_report_is_bounce(): void
    {
        $email = $this->email(['headers' => ['content-type' => 'multipart/report; report-type=delivery-status; boundary="x"']]);

        $this->assertSame(Reply::CLASS_BOUNCE, app(ReplyClassifier::class)->classify($email));
    }

    public function test_unsubscribe_phrases(): void
    {
        foreach (['Please unsubscribe me', 'REMOVE ME from this list', 'stop emailing me please', 'I want to opt out'] as $body) {
            $this->assertSame(
                Reply::CLASS_UNSUBSCRIBE,
                app(ReplyClassifier::class)->classify($this->email(['body' => $body])),
                "Body \"{$body}\" should classify as unsubscribe",
            );
        }
    }

    public function test_not_interested_is_a_reply_not_an_opt_out(): void
    {
        $email = $this->email(['body' => "Thanks but we're not interested right now."]);

        $this->assertSame(Reply::CLASS_REPLY, app(ReplyClassifier::class)->classify($email));
    }

    public function test_auto_submitted_header_is_auto_reply(): void
    {
        $email = $this->email(['headers' => ['auto-submitted' => 'auto-replied']]);

        $this->assertSame(Reply::CLASS_AUTO_REPLY, app(ReplyClassifier::class)->classify($email));
    }

    public function test_out_of_office_subject_is_auto_reply(): void
    {
        $email = $this->email(['subject' => 'Automatic reply: Quick question']);

        $this->assertSame(Reply::CLASS_AUTO_REPLY, app(ReplyClassifier::class)->classify($email));
    }

    public function test_bounce_beats_unsubscribe_phrase_in_dsn_body(): void
    {
        $email = $this->email([
            'from' => 'mailer-daemon@googlemail.com',
            'body' => 'Delivery failed. The address unsubscribe@example.com rejected the message.',
        ]);

        $this->assertSame(Reply::CLASS_BOUNCE, app(ReplyClassifier::class)->classify($email));
    }

    public function test_extracts_bounced_recipient_from_dsn(): void
    {
        $email = $this->email([
            'body' => "Reporting-MTA: dns; googlemail.com\nFinal-Recipient: rfc822; gone@example.com\nAction: failed\nStatus: 5.1.1",
        ]);

        $this->assertSame('gone@example.com', app(ReplyClassifier::class)->bouncedRecipient($email));
    }

    public function test_extracts_bounced_recipient_from_prose(): void
    {
        $email = $this->email([
            'body' => "Your message wasn't delivered to missing@example.org because the address couldn't be found.",
        ]);

        $this->assertSame('missing@example.org', app(ReplyClassifier::class)->bouncedRecipient($email));
    }
}
