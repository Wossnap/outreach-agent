<?php

namespace App\Services\Inbound;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Models\Suppression;
use App\Services\Sending\EnrollmentStopper;

/**
 * Turns a fetched inbox message into a Reply row and applies the outcome:
 * stop-on-reply, suppress + stop on bounce/unsubscribe, record-only for OOO.
 */
class InboundProcessor
{
    public function __construct(
        protected ReplyClassifier $classifier,
        protected EnrollmentStopper $stopper,
    ) {}

    public function process(Mailbox $mailbox, InboundEmail $email): ?Reply
    {
        if ($email->isSentByUs()) {
            return null;
        }

        if (Reply::query()->where('gmail_message_id', $email->gmailMessageId)->exists()) {
            return null;
        }

        $classification = $this->classifier->classify($email);
        [$enrollment, $contact, $message] = $this->match($mailbox, $email, $classification);

        // Unmatched inbox noise (newsletters, unrelated mail) is ignored
        // entirely — we only record traffic tied to an outreach thread/contact.
        if (! $enrollment && ! $contact) {
            return null;
        }

        $reply = Reply::query()->create([
            'mailbox_id' => $mailbox->id,
            'enrollment_id' => $enrollment?->id,
            'contact_id' => $contact?->id,
            'message_id' => $message?->id,
            'gmail_message_id' => $email->gmailMessageId,
            'gmail_thread_id' => $email->gmailThreadId,
            'from_email' => $email->fromEmail,
            'subject' => $email->subject,
            'snippet' => $email->snippet,
            'body_text' => mb_substr($email->bodyText, 0, 20000),
            'classification' => $classification,
            'received_at' => $email->receivedAt ?? now(),
        ]);

        $this->applyOutcome($reply, $enrollment, $contact, $email, $classification);

        return $reply;
    }

    /**
     * @return array{0: ?Enrollment, 1: ?Contact, 2: ?Message}
     */
    protected function match(Mailbox $mailbox, InboundEmail $email, string $classification): array
    {
        // Strongest signal: the Gmail thread of one of our sends.
        if ($email->gmailThreadId) {
            $message = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('gmail_thread_id', $email->gmailThreadId)
                ->latest('sent_at')
                ->first();

            if ($message) {
                return [$message->enrollment, $message->contact, $message];
            }
        }

        // For bounces the sender is the mail system, so match on the failed
        // recipient extracted from the DSN body; otherwise on the sender.
        $matchEmail = $classification === Reply::CLASS_BOUNCE
            ? $this->classifier->bouncedRecipient($email)
            : mb_strtolower($email->fromEmail);

        if (! $matchEmail) {
            return [null, null, null];
        }

        $contact = Contact::query()->where('email', $matchEmail)->first();

        if (! $contact) {
            return [null, null, null];
        }

        $enrollment = Enrollment::query()
            ->where('contact_id', $contact->id)
            ->where('mailbox_id', $mailbox->id)
            ->latest()
            ->first();

        return [$enrollment, $contact, null];
    }

    protected function applyOutcome(Reply $reply, ?Enrollment $enrollment, ?Contact $contact, InboundEmail $email, string $classification): void
    {
        match ($classification) {
            Reply::CLASS_BOUNCE => $this->handleBounce($reply, $enrollment, $contact),
            Reply::CLASS_UNSUBSCRIBE => $this->handleUnsubscribe($reply, $enrollment, $contact),
            Reply::CLASS_REPLY => $this->handleReply($enrollment),
            default => null, // auto_reply: record only, sequence continues
        };
    }

    protected function handleBounce(Reply $reply, ?Enrollment $enrollment, ?Contact $contact): void
    {
        $email = $contact?->email ?? $enrollment?->contact?->email;

        if ($email) {
            Suppression::suppress($email, Suppression::REASON_BOUNCED, $reply->id);
        }

        if ($enrollment) {
            $this->stopper->stop($enrollment, Enrollment::STATUS_STOPPED_BOUNCE, 'Delivery bounced');
        }
    }

    protected function handleUnsubscribe(Reply $reply, ?Enrollment $enrollment, ?Contact $contact): void
    {
        $email = $contact?->email ?? $enrollment?->contact?->email;

        if ($email) {
            Suppression::suppress($email, Suppression::REASON_UNSUBSCRIBED, $reply->id);
        }

        if ($enrollment) {
            $this->stopper->stop($enrollment, Enrollment::STATUS_STOPPED_UNSUBSCRIBE, 'Contact opted out');
        }
    }

    protected function handleReply(?Enrollment $enrollment): void
    {
        if ($enrollment) {
            $this->stopper->stop($enrollment, Enrollment::STATUS_STOPPED_REPLY, 'Contact replied');
        }
    }
}
