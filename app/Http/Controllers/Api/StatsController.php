<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Models\Suppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Stats
 *
 * Counts across the system in one request.
 */
class StatsController extends ApiController
{
    /**
     * Overview
     *
     * Counts across the system in one request, instead of paging through
     * every list to work them out.
     *
     * A rate comes back as null rather than 0 when nothing has been sent,
     * because 0 would read as "nobody replied" instead of "no data yet".
     *
     * Opens count messages that carried a tracking pixel and were fetched at
     * least once; the figure is directional. `postmaster` is Gmail's own
     * user-reported spam rate per sending domain, from Postmaster Tools.
     *
     * @queryParam since string ISO date. Narrows the sent, open, reply and bounce counts to that window. Totals and the queue are current state and are not narrowed. e.g. 2026-08-01. No-example
     */
    public function index(Request $request): JsonResponse
    {
        $since = $request->query('since');

        $sent = Message::query()->where('status', Message::STATUS_SENT);
        $replies = Reply::query();

        if ($since) {
            $sent->where('sent_at', '>=', $since);
            $replies->where('received_at', '>=', $since);
        }

        $sentCount = (clone $sent)->count();
        $tracked = (clone $sent)->whereNotNull('open_token')->count();
        $opened = (clone $sent)->whereNotNull('first_opened_at')->count();
        $bounces = (clone $replies)->where('classification', Reply::CLASS_BOUNCE)->count();
        $genuineReplies = (clone $replies)->where('classification', Reply::CLASS_REPLY)->count();

        return $this->ok([
            'window_since' => $since,
            'contacts' => [
                'total' => Contact::query()->count(),
                'suppressed' => Suppression::query()->count(),
            ],
            'enrollments' => [
                'active' => Enrollment::query()->where('status', Enrollment::STATUS_ACTIVE)->count(),
                // People in a sequence with nowhere to send yet. Reported
                // separately because it is the number that says whether the
                // waterfall is keeping up: a total that climbs while active
                // stands still means addresses are not being confirmed.
                'waiting_email' => Enrollment::query()->where('status', Enrollment::STATUS_WAITING_EMAIL)->count(),
                'total' => Enrollment::query()->count(),
            ],
            'queue' => [
                'pending_approval' => Message::query()->where('status', Message::STATUS_PENDING_APPROVAL)->count(),
                'scheduled' => Message::query()->where('status', Message::STATUS_SCHEDULED)->count(),
                'draft_failed' => Message::query()->where('status', Message::STATUS_DRAFT_FAILED)->count(),
                'send_failed' => Message::query()->where('status', Message::STATUS_FAILED)->count(),
            ],
            'sending' => [
                'sent' => $sentCount,
                'replies' => $genuineReplies,
                'bounces' => $bounces,
                'unsubscribes' => (clone $replies)->where('classification', Reply::CLASS_UNSUBSCRIBE)->count(),
                // Null rather than zero when nothing has been sent: a rate of
                // zero would read as "nobody replied" instead of "no data".
                'reply_rate' => $sentCount > 0 ? round($genuineReplies / $sentCount, 4) : null,
                'bounce_rate' => $sentCount > 0 ? round($bounces / $sentCount, 4) : null,
                // Opens are counted against messages that carried a pixel.
                // Directional: image blocking undercounts, mail proxies overcount.
                'tracked' => $tracked,
                'opened' => $opened,
                'open_rate' => $tracked > 0 ? round($opened / $tracked, 4) : null,
            ],
            // Gmail's own user-reported spam rate per sending domain, from
            // Postmaster Tools. Null until a domain is verified there and has
            // enough Gmail traffic to be reported on.
            'postmaster' => $this->postmaster(),
            'mailboxes' => [
                'total' => Mailbox::query()->count(),
                'active' => Mailbox::query()->where('status', Mailbox::STATUS_ACTIVE)->count(),
                'paused' => Mailbox::query()->where('status', Mailbox::STATUS_PAUSED)->count(),
                'disconnected' => Mailbox::query()->where('status', Mailbox::STATUS_DISCONNECTED)->count(),
            ],
        ]);
    }

    /**
     * @return array{worst: ?array{domain: string, date: string, spam_rate: ?float}, domains: array<int, array<string, mixed>>}
     */
    protected function postmaster(): array
    {
        $domains = Domain::query()->with('latestPostmasterStat')->orderBy('name')->get();

        $rows = $domains->map(fn (Domain $domain) => [
            'domain' => $domain->name,
            'date' => $domain->latestPostmasterStat?->date?->toDateString(),
            'spam_rate' => $domain->latestPostmasterStat?->spam_rate,
            'auth_success_rate' => $domain->latestPostmasterStat?->auth_success_rate,
            'delivery_error_rate' => $domain->latestPostmasterStat?->delivery_error_rate,
            'verification' => $domain->postmaster_verification,
            'synced_at' => $domain->postmaster_synced_at?->toIso8601String(),
            'error' => $domain->postmaster_error,
        ])->values();

        $worst = $rows->filter(fn (array $row) => $row['spam_rate'] !== null)->sortByDesc('spam_rate')->first();

        return [
            'worst' => $worst ? ['domain' => $worst['domain'], 'date' => $worst['date'], 'spam_rate' => $worst['spam_rate']] : null,
            'domains' => $rows->all(),
        ];
    }
}
