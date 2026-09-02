<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ReplyResource;
use App\Models\Reply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Replies
 *
 * What came back: genuine replies, bounces, auto-responders and opt-outs.
 */
class ReplyController extends ApiController
{
    /**
     * List replies
     *
     * Newest first.
     *
     * @queryParam classification string One of reply, bounce, auto_reply, unsubscribe. e.g. reply. No-example
     * @queryParam mailbox_id integer Only replies to this mailbox. e.g. 1. No-example
     * @queryParam contact_id integer Only replies from this contact. e.g. 1. No-example
     * @queryParam enrollment_id integer Only replies on this enrollment. e.g. 1. No-example
     * @queryParam unread boolean Only replies nobody has opened yet. e.g. true. No-example
     * @queryParam since string ISO date. Only replies received on or after it. e.g. 2026-08-01. No-example
     * @queryParam per_page integer Rows per page. Clamped to 200. e.g. 50. No-example
     * @queryParam page integer Which page to return. e.g. 1. No-example
     */
    public function index(Request $request): JsonResponse
    {
        $query = Reply::query()->with('contact')->latest('received_at');

        foreach (['classification', 'mailbox_id', 'contact_id', 'enrollment_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        if ($since = $request->query('since')) {
            $query->where('received_at', '>=', $since);
        }

        return $this->paged($query->paginate($this->perPage($request)), ReplyResource::class);
    }

    /**
     * Get one reply
     *
     * @urlParam reply integer required Example: 1
     */
    public function show(int $reply): JsonResponse
    {
        $model = Reply::query()->with('contact')->find($reply);

        return $model
            ? $this->ok(new ReplyResource($model))
            : $this->fail('Reply not found.', 404);
    }
}
