<?php

namespace App\Services\Reporting;

use App\Models\Domain;
use App\Models\Message;
use App\Models\Reply;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The numbers on the dashboard: sent, opened, replied, bounced, and Gmail's
 * spam rate. One place, so the page and the API cannot disagree.
 *
 * Every count is over a window that starts at `since` (null for all time).
 * Sends are placed by sent_at, replies by received_at. Opens are measured
 * against messages that carried a pixel, not against everything sent, or the
 * rate would sink whenever tracking was off for part of the window.
 */
class OutreachAnalytics
{
    /**
     * @return array{sent: int, tracked: int, opened: int, replied: int, bounced: int, unsubscribed: int, open_rate: ?float, reply_rate: ?float, bounce_rate: ?float}
     */
    public function totals(?CarbonInterface $since): array
    {
        $sent = Message::query()
            ->where('status', Message::STATUS_SENT)
            ->when($since, fn ($q) => $q->where('sent_at', '>=', $since));

        $replies = Reply::query()
            ->when($since, fn ($q) => $q->where('received_at', '>=', $since));

        $sentCount = (clone $sent)->count();
        $tracked = (clone $sent)->whereNotNull('open_token')->count();
        $opened = (clone $sent)->whereNotNull('first_opened_at')->count();
        $replied = (clone $replies)->where('classification', Reply::CLASS_REPLY)->count();
        $bounced = (clone $replies)->where('classification', Reply::CLASS_BOUNCE)->count();
        $unsubscribed = (clone $replies)->where('classification', Reply::CLASS_UNSUBSCRIBE)->count();

        // Null rather than zero when there is nothing to divide by: zero
        // would read as "nobody opened" instead of "no data yet".
        return [
            'sent' => $sentCount,
            'tracked' => $tracked,
            'opened' => $opened,
            'replied' => $replied,
            'bounced' => $bounced,
            'unsubscribed' => $unsubscribed,
            'open_rate' => $tracked > 0 ? round($opened / $tracked, 4) : null,
            'reply_rate' => $sentCount > 0 ? round($replied / $sentCount, 4) : null,
            'bounce_rate' => $sentCount > 0 ? round($bounced / $sentCount, 4) : null,
        ];
    }

    /**
     * One entry per calendar day from `from` to `to`, in the display timezone.
     *
     * Grouped in SQL by the day in that timezone rather than in UTC, so an
     * email sent at 23:30 UTC lands on the day the person sending it would
     * say it went. Opens are attributed to the day the email was sent.
     *
     * @return array<int, array{day: string, sent: int, opened: int, replied: int}>
     */
    public function daily(CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = config('outreach.timezone');
        $from = CarbonImmutable::instance($from)->setTimezone($tz)->startOfDay();
        $to = CarbonImmutable::instance($to)->setTimezone($tz)->startOfDay();

        // Postgres: the column holds UTC without a zone, so it is told so
        // before being moved to the display zone. Aggregates are aliased
        // because Postgres names an unaliased COUNT(*) its own way.
        $sent = Message::query()
            ->where('status', Message::STATUS_SENT)
            ->where('sent_at', '>=', $from->utc())
            ->selectRaw("((sent_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date AS day, COUNT(*) AS sent, COUNT(first_opened_at) AS opened", [$tz])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $replied = Reply::query()
            ->where('classification', Reply::CLASS_REPLY)
            ->where('received_at', '>=', $from->utc())
            ->selectRaw("((received_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date AS day, COUNT(*) AS replied", [$tz])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $key = $day->toDateString();

            $days[] = [
                'day' => $key,
                'sent' => (int) ($sent[$key]->sent ?? 0),
                'opened' => (int) ($sent[$key]->opened ?? 0),
                'replied' => (int) ($replied[$key]->replied ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Sent, opened and replied per automation, busiest first.
     *
     * @return array<int, array{automation_id: int, name: string, sent: int, opened: int, replied: int, open_rate: ?float, reply_rate: ?float}>
     */
    public function byAutomation(?CarbonInterface $since): array
    {
        $sent = Message::query()
            ->join('enrollments', 'enrollments.id', '=', 'messages.enrollment_id')
            ->join('automations', 'automations.id', '=', 'enrollments.automation_id')
            ->where('messages.status', Message::STATUS_SENT)
            ->when($since, fn ($q) => $q->where('messages.sent_at', '>=', $since))
            ->selectRaw('automations.id AS automation_id, automations.name AS automation_name, COUNT(*) AS sent, COUNT(messages.first_opened_at) AS opened, COUNT(messages.open_token) AS tracked')
            ->groupBy('automations.id', 'automations.name')
            ->get();

        $replied = Reply::query()
            ->join('enrollments', 'enrollments.id', '=', 'replies.enrollment_id')
            ->where('replies.classification', Reply::CLASS_REPLY)
            ->when($since, fn ($q) => $q->where('replies.received_at', '>=', $since))
            ->selectRaw('enrollments.automation_id AS automation_id, COUNT(*) AS replied')
            ->groupBy('enrollments.automation_id')
            ->get()
            ->keyBy('automation_id');

        return $sent
            ->map(fn ($row) => [
                'automation_id' => (int) $row->automation_id,
                'name' => $row->automation_name,
                'sent' => (int) $row->sent,
                'opened' => (int) $row->opened,
                'replied' => (int) ($replied[$row->automation_id]->replied ?? 0),
                'open_rate' => (int) $row->tracked > 0 ? round((int) $row->opened / (int) $row->tracked, 4) : null,
                'reply_rate' => (int) $row->sent > 0 ? round((int) ($replied[$row->automation_id]->replied ?? 0) / (int) $row->sent, 4) : null,
            ])
            ->sortByDesc('sent')
            ->values()
            ->all();
    }

    /**
     * Every sending domain with its latest Postmaster figures, by name.
     *
     * @return Collection<int, Domain>
     */
    public function spamByDomain(): Collection
    {
        return Domain::query()->with(['latestPostmasterStat', 'mailboxes'])->orderBy('name')->get();
    }

    /**
     * The domain Gmail currently rates worst, or null when nothing has been
     * reported. Worst rather than average because the spam rate is a
     * threshold Gmail applies per domain: one domain over it is the problem.
     */
    public function worstSpamDomain(Collection $domains): ?Domain
    {
        return $domains
            ->filter(fn (Domain $d) => $d->latestPostmasterStat?->spam_rate !== null)
            ->sortByDesc(fn (Domain $d) => $d->latestPostmasterStat->spam_rate)
            ->first();
    }
}
