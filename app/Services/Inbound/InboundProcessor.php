<?php

namespace App\Services\Inbound;

use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
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
        $contact ??= $enrollment?->contact;
        $email = $contact?->email;

        if ($email) {
            Suppression::suppress($email, Suppression::REASON_BOUNCED, $reply->id);
        }

        if ($contact) {
            $this->recordTheBounceAgainstTheAddress($contact, $reply);
        }

        if ($enrollment) {
            $this->stopper->stop($enrollment, Enrollment::STATUS_STOPPED_BOUNCE, 'Delivery bounced');
        }

        $this->stopEverythingElseOpenFor($contact, $enrollment, Enrollment::STATUS_STOPPED_BOUNCE, 'Their address bounced');
    }

    /**
     * Suppressing somebody ends every sequence they are in, not just this one.
     *
     * Only the enrollment that produced the message is matched here, and a
     * waiting one can never be matched at all: matching is scoped to the
     * mailbox the mail arrived at, and an enrollment waiting for an address has
     * no mailbox yet. So the same person could be in a second sequence,
     * unstopped, after the first had proved their address was dead or they had
     * asked never to be written to again.
     *
     * Nothing would have been sent - starting a waiting enrollment checks the
     * opt-out list first - but the row stayed open for good, with nothing on
     * any screen to say why it never went anywhere.
     */
    protected function stopEverythingElseOpenFor(?Contact $contact, ?Enrollment $matched, string $status, string $reason): void
    {
        if (! $contact) {
            return;
        }

        $others = Enrollment::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', Enrollment::openStatuses())
            ->when($matched, fn ($query) => $query->whereKeyNot($matched->id))
            ->get();

        foreach ($others as $enrollment) {
            $this->stopper->stop($enrollment, $status, $reason);
        }
    }

    /**
     * Tell the email waterfall that an address it approved did not deliver.
     *
     * This is the only true measure of whether a "valid" verdict was right. A
     * bounce writes an ordinary lookup row, so the provider that supplied the
     * address is answerable for it on the same page that reports what it cost.
     *
     * Free, because nobody charged us for it. The status goes to invalid so the
     * address is never sent to again even if the suppression list is later
     * cleared, and so nothing re-checks it: a bounce is as settled as it gets.
     */
    protected function recordTheBounceAgainstTheAddress(Contact $contact, Reply $reply): void
    {
        EmailLookup::create([
            'contact_id' => $contact->id,
            'provider_name' => 'Delivery',
            'driver' => EmailLookup::DRIVER_BOUNCE,
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'result' => EmailLookup::RESULT_INVALID,
            'cost' => 0,
            'detail' => [
                'reason' => 'The message bounced, so this address does not accept mail.',
                'found_by' => $contact->email_provider,
                'said_before_sending' => $contact->email_status,
                'reply_id' => $reply->id,
            ],
        ]);

        $contact->update([
            'email_status' => Contact::EMAIL_INVALID,
            'email_checked_at' => now(),
        ]);
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

        // Somebody asking not to be written to again means all of it, not the
        // one sequence the message happened to be matched to.
        $this->stopEverythingElseOpenFor(
            $contact ?? $enrollment?->contact,
            $enrollment,
            Enrollment::STATUS_STOPPED_UNSUBSCRIBE,
            'They opted out',
        );
    }

    protected function handleReply(?Enrollment $enrollment): void
    {
        if ($enrollment) {
            $this->stopper->stop($enrollment, Enrollment::STATUS_STOPPED_REPLY, 'Contact replied');
        }
    }
}
