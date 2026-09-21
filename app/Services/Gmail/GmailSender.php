<?php

namespace App\Services\Gmail;

use App\Models\Message;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Support\Facades\Storage;
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

        $gmail = $this->clientFactory->gmailFor($mailbox);

        $sent = $gmail->users_messages->send('me', $gmailMessage);

        // Gmail discards the Message-ID we set and assigns its own. Follow-ups
        // quote this value in In-Reply-To/References, so storing ours would
        // point them at a message that does not exist and the recipient's mail
        // client would file the follow-up as a separate conversation.
        $rfcMessageId = $this->sentMessageId($gmail, $sent->getId()) ?? $rfcMessageId;

        return [
            'gmail_message_id' => $sent->getId(),
            'gmail_thread_id' => $sent->getThreadId(),
            'rfc_message_id' => $rfcMessageId,
        ];
    }

    /**
     * The Message-ID Gmail actually assigned to a sent message.
     */
    protected function sentMessageId(Gmail $gmail, string $gmailMessageId): ?string
    {
        $sent = $gmail->users_messages->get('me', $gmailMessageId, [
            'format' => 'metadata',
            'metadataHeaders' => ['Message-ID'],
        ]);

        foreach ($sent->getPayload()?->getHeaders() ?? [] as $header) {
            if (mb_strtolower($header->getName()) === 'message-id') {
                return $header->getValue();
            }
        }

        return null;
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

        $attachments = $message->sequenceStep?->attachmentList() ?? [];
        $body = $this->bodyPart($message);

        if ($attachments === []) {
            return implode("\r\n", array_merge($headers, $body['headers']))."\r\n\r\n".$body['content'];
        }

        return $this->buildMultipart($headers, $body, $attachments);
    }

    /**
     * The body as one MIME unit: headers and content.
     *
     * Plain text, byte for byte what was always sent, unless the message is
     * being tracked. Then it is multipart/alternative with the same text and
     * an HTML twin carrying the open pixel. Tracking is decided by the token,
     * not the switch alone: the switch says whether tokens get assigned, the
     * token says whether this message got one.
     *
     * @return array{headers: array<int, string>, content: string}
     */
    protected function bodyPart(Message $message): array
    {
        $pixelUrl = $this->trackingPixelUrl($message);

        $text = [
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBody($message->body_text),
        ];

        if ($pixelUrl === null) {
            return ['headers' => array_slice($text, 0, 2), 'content' => $text[3]];
        }

        // A different prefix from the mixed boundary, so the two can never
        // collide when an attachment nests this inside multipart/mixed.
        $boundary = 'alt-'.Str::random(30);

        $html = [
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBody($this->htmlBody($message->body_text, $pixelUrl)),
        ];

        // Text first, HTML last: a client picks the last alternative it can
        // show, and the pixel only fires from the HTML one.
        return [
            'headers' => ['Content-Type: multipart/alternative; boundary="'.$boundary.'"'],
            'content' => '--'.$boundary."\r\n"
                .implode("\r\n", $text)."\r\n"
                .'--'.$boundary."\r\n"
                .implode("\r\n", $html)."\r\n"
                .'--'.$boundary.'--',
        ];
    }

    /**
     * Where the open pixel for this message lives, or null when it has none.
     */
    protected function trackingPixelUrl(Message $message): ?string
    {
        if (! config('outreach.open_tracking.enabled') || blank($message->open_token)) {
            return null;
        }

        return route('track.open', ['token' => $message->open_token]);
    }

    /**
     * The plain text as HTML, and nothing more.
     *
     * The same words, escaped, with line breaks kept and bare URLs made
     * clickable, so the HTML reads exactly like the text and a spam filter
     * comparing the two parts finds them saying the same thing. The image is
     * not hidden: some clients skip images styled display:none.
     */
    public function htmlBody(string $text, string $pixelUrl): string
    {
        $escaped = e($text);

        // Trailing punctuation belongs to the sentence, not the link.
        $linked = preg_replace_callback('~https?://[^\s<>"]+~i', function (array $match): string {
            $url = rtrim($match[0], '.,;:!?)');
            $trailing = substr($match[0], strlen($url));

            return '<a href="'.$url.'">'.$url.'</a>'.$trailing;
        }, $escaped);

        return '<!DOCTYPE html><html><body>'
            .'<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">'
            .nl2br($linked, false)
            .'</div>'
            .'<img src="'.e($pixelUrl).'" width="1" height="1" alt="" style="border:0;width:1px;height:1px">'
            .'</body></html>';
    }

    /**
     * multipart/mixed: the body unit as the first part, then one part per file.
     *
     * @param  array<int, string>  $headers
     * @param  array{headers: array<int, string>, content: string}  $body
     * @param  array<int, array{disk: string, path: string, filename: string, mime: string, size: int}>  $attachments
     */
    protected function buildMultipart(array $headers, array $body, array $attachments): string
    {
        $boundary = 'outreach-'.Str::random(30);

        $headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';

        $parts = [implode("\r\n", $body['headers'])."\r\n\r\n".$body['content']];

        foreach ($attachments as $attachment) {
            $disk = Storage::disk($attachment['disk']);

            // Deliberately fatal. Sending anyway would deliver an email whose
            // text refers to a file that is not on it, and nobody would know.
            // Failing puts it on the Activity page with the path that is gone.
            if (! $disk->exists($attachment['path'])) {
                throw new RuntimeException(
                    'Attachment missing from storage: '.$attachment['path'].' ('.$attachment['filename'].')'
                );
            }

            // A quote in the filename would close the header parameter early.
            $filename = $this->encodeHeader(str_replace('"', '', $attachment['filename']));

            $parts[] = implode("\r\n", [
                'Content-Type: '.$attachment['mime'].'; name="'.$filename.'"',
                'Content-Disposition: attachment; filename="'.$filename.'"',
                'Content-Transfer-Encoding: base64',
                '',
                rtrim(chunk_split(base64_encode($disk->get($attachment['path'])), 76, "\r\n")),
            ]);
        }

        return implode("\r\n", $headers)."\r\n\r\n"
            .'--'.$boundary."\r\n"
            .implode("\r\n--".$boundary."\r\n", $parts)
            ."\r\n--".$boundary."--\r\n";
    }

    protected function encodeBody(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
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
