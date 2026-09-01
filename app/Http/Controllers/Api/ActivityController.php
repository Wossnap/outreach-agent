<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Activity
 *
 * The system log: sends, failures, bounces, opt-outs and polling errors.
 */
class ActivityController extends ApiController
{
    /**
     * The activity log, newest first: sends, failures, bounces, opt-outs and
     * polling errors.
     *
     * Filters: ?level=info|warning|error, ?event=, ?retryable=true,
     * ?since= (ISO date).
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->latest('id');

        foreach (['level', 'event'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('retryable')) {
            $query->where('retryable', $request->boolean('retryable'));
        }

        if ($since = $request->query('since')) {
            $query->where('created_at', '>=', $since);
        }

        return $this->paged($query->paginate($this->perPage($request)), ActivityLogResource::class);
    }
}
