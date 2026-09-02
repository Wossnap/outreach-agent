<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\HealthCheck;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Services\Health\AutoPauseRules;
use Illuminate\Console\Command;

/**
 * Rolls up 7-day engagement per mailbox and applies the auto-pause rules:
 * high bounce rate, blocklisted domain, or missing SPF/DKIM pauses sending.
 * Un-pausing is deliberately manual.
 */
class EvaluateMailboxHealth extends Command
{
    protected $signature = 'health:evaluate';

    protected $description = 'Compute rolling bounce/reply rates per mailbox and auto-pause unhealthy ones';

    public function handle(): int
    {
        $since = now()->subDays(7);

        foreach (Mailbox::query()->with('domain')->get() as $mailbox) {
            $sent = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('status', Message::STATUS_SENT)
                ->where('sent_at', '>=', $since)
                ->count();

            $bounces = Reply::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('classification', Reply::CLASS_BOUNCE)
                ->where('received_at', '>=', $since)
                ->count();

            $replies = Reply::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('classification', Reply::CLASS_REPLY)
                ->where('received_at', '>=', $since)
                ->count();

            $bounceRate = $sent > 0 ? round($bounces / $sent, 4) : 0.0;
            $replyRate = $sent > 0 ? round($replies / $sent, 4) : 0.0;

            $health = $this->healthFor($mailbox, $sent, $bounceRate);

            $mailbox->update([
                'sent_7d' => $sent,
                'bounce_rate_7d' => $bounceRate,
                'reply_rate_7d' => $replyRate,
                'health_status' => $health,
            ]);

            HealthCheck::query()->create([
                'checkable_type' => $mailbox->getMorphClass(),
                'checkable_id' => $mailbox->id,
                'check_type' => 'engagement',
                'status' => $health === Domain::HEALTH_HEALTHY ? HealthCheck::STATUS_OK : ($health === Domain::HEALTH_CRITICAL ? HealthCheck::STATUS_FAIL : HealthCheck::STATUS_WARN),
                'detail' => ['sent_7d' => $sent, 'bounce_rate' => $bounceRate, 'reply_rate' => $replyRate],
                'checked_at' => now(),
            ]);

            $this->maybeAutoPause($mailbox);
        }

        $this->info('Evaluated '.Mailbox::query()->count().' mailboxes.');

        return self::SUCCESS;
    }

    protected function healthFor(Mailbox $mailbox, int $sent, float $bounceRate): string
    {
        $threshold = (float) config('outreach.bounce_rate_pause_threshold');

        if ($mailbox->domain->dnsbl_listed || in_array('missing', [$mailbox->domain->spf_status, $mailbox->domain->dkim_status], true)) {
            return Domain::HEALTH_CRITICAL;
        }

        if ($sent >= (int) config('outreach.bounce_rate_min_sends') && $bounceRate > $threshold) {
            return Domain::HEALTH_CRITICAL;
        }

        if ($bounceRate > $threshold / 2 || $mailbox->domain->health_status === Domain::HEALTH_WARNING) {
            return Domain::HEALTH_WARNING;
        }

        return Domain::HEALTH_HEALTHY;
    }

    protected function maybeAutoPause(Mailbox $mailbox): void
    {
        if ($mailbox->status !== Mailbox::STATUS_ACTIVE) {
            return;
        }

        // Same rules the Mailboxes page uses to ask whether a pause still
        // applies, so the two can never give different answers.
        $cause = app(AutoPauseRules::class)->currentReason($mailbox);

        if ($cause === null) {
            return;
        }

        $reason = 'Auto-paused: '.lcfirst($cause);

        $mailbox->pause($reason);

        ActivityLog::record(
            event: 'mailbox_paused',
            message: "{$mailbox->email}: {$reason} Fix the cause, then resume it from the Mailboxes page.",
            level: ActivityLog::LEVEL_ERROR,
            subject: $mailbox,
        );
    }
}
