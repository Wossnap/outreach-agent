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
     * List activity
     *
     * The system log, newest first: sends, failures, bounces, opt-outs and
     * polling errors. This is what to watch for problems.
     *
     * @queryParam level string One of info, warning, error. Example: error
     * @queryParam event string Exact event name. Example: send_failed
     * @queryParam retryable boolean Only entries the system can retry. Example: true
     * @queryParam since string ISO date. Only entries logged on or after it. Example: 2026-08-01
     * @queryParam per_page integer Rows per page. Clamped to 200. Example: 50
     * @queryParam page integer Which page to return. Example: 1
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
