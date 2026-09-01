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
     * List replies received, newest first.
     *
     * Filters: ?classification=reply|bounce|auto_reply|unsubscribe,
     * ?mailbox_id=, ?contact_id=, ?unread=true, ?since= (ISO date).
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

    public function show(int $reply): JsonResponse
    {
        $model = Reply::query()->with('contact')->find($reply);

        return $model
            ? $this->ok(new ReplyResource($model))
            : $this->fail('Reply not found.', 404);
    }
}
