<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
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
     * Counts across the system, so a caller can see the state of things in one
     * request instead of paging through every list.
     *
     * ?since= (ISO date) narrows the sent/reply/bounce counts to that window.
     * It does not narrow the totals or the queue, which are current state.
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
            ],
            'mailboxes' => [
                'total' => Mailbox::query()->count(),
                'active' => Mailbox::query()->where('status', Mailbox::STATUS_ACTIVE)->count(),
                'paused' => Mailbox::query()->where('status', Mailbox::STATUS_PAUSED)->count(),
                'disconnected' => Mailbox::query()->where('status', Mailbox::STATUS_DISCONNECTED)->count(),
            ],
        ]);
    }
}
