<?php

namespace App\Services\Health;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;

/**
 * The conditions under which a mailbox is stopped from sending.
 *
 * One place, asked two questions. The scheduled evaluation asks "should this
 * be paused?", and the Mailboxes page asks "is the reason still true?".
 *
 * Those have to be the same rules. A paused mailbox stores the reason as text
 * and nothing ever revisits it, so the page was still saying "missing SPF or
 * DKIM" days after the records were added. Re-running the rules is what keeps
 * the answer honest.
 */
class AutoPauseRules
{
    /**
     * Why this mailbox should not be sending right now, or null if nothing is
     * wrong with it.
     */
    public function currentReason(Mailbox $mailbox): ?string
    {
        $domain = $mailbox->domain;

        if ($domain?->dnsbl_listed) {
            return 'Domain '.$domain->name.' is listed on '.implode(', ', $domain->dnsbl_zones ?? []).'.';
        }

        if ($domain && in_array('missing', [$domain->spf_status, $domain->dkim_status], true)) {
            $missing = [];

            if ($domain->spf_status === 'missing') {
                $missing[] = 'SPF';
            }

            if ($domain->dkim_status === 'missing') {
                $missing[] = 'DKIM';
            }

            return 'Domain '.$domain->name.' is missing '.implode(' and ', $missing).'.';
        }

        [$sent, $bounceRate] = $this->recentSending($mailbox);

        if ($sent >= (int) config('outreach.bounce_rate_min_sends')
            && $bounceRate > (float) config('outreach.bounce_rate_pause_threshold')) {
            return 'Bounce rate '.number_format($bounceRate * 100, 1)."% over the last 7 days ({$sent} sends).";
        }

        return null;
    }

    /**
     * A short statement of the domain's authentication, for showing alongside
     * the verdict so the reader can see what it is based on.
     */
    public function domainSummary(Mailbox $mailbox): ?string
    {
        $domain = $mailbox->domain;

        if (! $domain || $domain->spf_status === 'unknown') {
            return null;
        }

        return implode(', ', [
            'SPF '.$domain->spf_status,
            'DKIM '.$domain->dkim_status,
            'DMARC '.$domain->statusLabel('dmarc_status'),
        ]);
    }

    /**
     * @return array{0: int, 1: float} sends and bounce rate over 7 days
     */
    protected function recentSending(Mailbox $mailbox): array
    {
        $since = now()->subDays(7);

        $sent = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('status', Message::STATUS_SENT)
            ->where('sent_at', '>=', $since)
            ->count();

        if ($sent === 0) {
            return [0, 0.0];
        }

        $bounces = Reply::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('classification', Reply::CLASS_BOUNCE)
            ->where('received_at', '>=', $since)
            ->count();

        return [$sent, $bounces / $sent];
    }
}
