<?php

namespace App\Services\Inbound;

use App\Models\Reply;
use Illuminate\Support\Str;

class ReplyClassifier
{
    public function classify(InboundEmail $email): string
    {
        if ($this->isBounce($email)) {
            return Reply::CLASS_BOUNCE;
        }

        if ($this->isAutoReply($email)) {
            return Reply::CLASS_AUTO_REPLY;
        }

        if ($this->isUnsubscribe($email)) {
            return Reply::CLASS_UNSUBSCRIBE;
        }

        return Reply::CLASS_REPLY;
    }

    protected function isBounce(InboundEmail $email): bool
    {
        $from = mb_strtolower($email->fromEmail);

        if (Str::startsWith($from, ['mailer-daemon@', 'postmaster@'])) {
            return true;
        }

        $contentType = mb_strtolower($email->header('content-type') ?? '');

        return str_contains($contentType, 'report-type=delivery-status');
    }

    protected function isAutoReply(InboundEmail $email): bool
    {
        $autoSubmitted = mb_strtolower($email->header('auto-submitted') ?? '');

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if ($email->header('x-autoreply') || $email->header('x-autorespond')) {
            return true;
        }

        $subject = mb_strtolower($email->subject);

        foreach (['out of office', 'automatic reply', 'auto-reply', 'autoreply', 'ooo:', 'on vacation', 'away from the office'] as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function isUnsubscribe(InboundEmail $email): bool
    {
        $haystack = mb_strtolower($email->subject.' '.$email->bodyText);

        foreach (config('outreach.unsubscribe_phrases', []) as $phrase) {
            if (str_contains($haystack, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort extraction of the failed recipient from a DSN bounce body.
     */
    public function bouncedRecipient(InboundEmail $email): ?string
    {
        if (preg_match('/Final-Recipient:\s*rfc822;\s*([^\s;]+@[^\s;]+)/i', $email->bodyText, $m)) {
            return mb_strtolower(trim($m[1], '<>'));
        }

        if (preg_match('/(?:delivery to the following recipient failed|wasn\'t delivered to|could not be delivered to)[^\n]*?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $email->bodyText, $m)) {
            return mb_strtolower($m[1]);
        }

        return null;
    }
}
