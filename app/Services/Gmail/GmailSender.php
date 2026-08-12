<?php

namespace App\Services\Gmail;

use App\Models\Message;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Support\Str;
use RuntimeException;

class GmailSender
{
    public function __construct(
        protected GmailClientFactory $clientFactory,
    ) {}

    /**
     * Send the message via the Gmail API.
     *
     * @return array{gmail_message_id: string, gmail_thread_id: string, rfc_message_id: string}
     */
    public function send(Message $message): array
    {
        $mailbox = $message->mailbox;

        if (! $mailbox) {
            throw new RuntimeException("Message #{$message->id} has no mailbox.");
        }

        $rfcMessageId = $message->rfc_message_id ?: $this->generateRfcMessageId($mailbox->email);

        $gmailMessage = new GmailMessage;
        $gmailMessage->setRaw($this->base64UrlEncode($this->buildMime($message, $rfcMessageId)));

        // Threading: follow-ups carry the enrollment's Gmail thread id so
        // they land in the same conversation on both sides.
        $threadId = $message->enrollment?->gmail_thread_id;

        if ($threadId) {
            $gmailMessage->setThreadId($threadId);
        }

        $sent = $this->clientFactory->gmailFor($mailbox)->users_messages->send('me', $gmailMessage);

        return [
            'gmail_message_id' => $sent->getId(),
            'gmail_thread_id' => $sent->getThreadId(),
            'rfc_message_id' => $rfcMessageId,
        ];
    }

    /**
     * Raw RFC 2822 message. Public so tests can assert headers without
     * touching the Gmail API.
     */
    public function buildMime(Message $message, string $rfcMessageId): string
    {
        $mailbox = $message->mailbox;
        $contact = $message->contact;
        $isFollowUp = ($message->sequenceStep?->position ?? 1) > 1;

        $subject = $message->subject;

        $priorSent = $isFollowUp ? $this->priorSentMessages($message) : collect();

        if ($isFollowUp && $priorSent->isNotEmpty()) {
            $originalSubject = $priorSent->first()->subject;
            $subject = Str::startsWith(mb_strtolower($originalSubject), 're:')
                ? $originalSubject
                : 'Re: '.$originalSubject;
        }

        $from = $mailbox->display_name
            ? $this->encodeDisplayName($mailbox->display_name).' <'.$mailbox->email.'>'
            : $mailbox->email;

        $to = $contact->name
            ? $this->encodeDisplayName($contact->name).' <'.$contact->email.'>'
            : $contact->email;

        $headers = [
            'From: '.$from,
            'To: '.$to,
            'Subject: '.$this->encodeHeader($subject),
            'Message-ID: '.$rfcMessageId,
            'Date: '.now()->toRfc2822String(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        if ($priorSent->isNotEmpty()) {
            $priorIds = $priorSent->pluck('rfc_message_id')->filter();

            if ($priorIds->isNotEmpty()) {
                $headers[] = 'In-Reply-To: '.$priorIds->last();
                $headers[] = 'References: '.$priorIds->implode(' ');
            }
        }

        if (config('outreach.list_unsubscribe_header')) {
            $headers[] = 'List-Unsubscribe: <mailto:'.$mailbox->email.'?subject=unsubscribe>';
        }

        return implode("\r\n", $headers)."\r\n\r\n".rtrim(chunk_split(base64_encode($message->body_text), 76, "\r\n"));
    }

    protected function priorSentMessages(Message $message)
    {
        return $message->enrollment->messages()
            ->where('status', Message::STATUS_SENT)
            ->where('id', '!=', $message->id)
            ->orderBy('sent_at')
            ->get();
    }

    protected function generateRfcMessageId(string $senderEmail): string
    {
        return '<'.Str::uuid().'@'.Str::after($senderEmail, '@').'>';
    }

    protected function encodeDisplayName(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9 .\'-]+$/', $name)) {
            return '"'.addcslashes($name, '"\\').'"';
        }

        return $this->encodeHeader($name);
    }

    protected function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
