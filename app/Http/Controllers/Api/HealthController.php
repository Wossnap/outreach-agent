<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/**
 * @group Health
 *
 * Is the API up.
 */
class HealthController extends ApiController
{
    /**
     * Health check
     *
     * Confirms the API is up. The only endpoint that needs no API key, so it
     * is safe to poll from a monitor.
     *
     * @unauthenticated
     *
     * @response 200 {"success": true, "message": "ok", "data": {"time": "2026-09-01T09:00:00+00:00"}}
     */
    public function __invoke(): JsonResponse
    {
        return $this->ok(['time' => now()->toIso8601String()]);
    }
}
