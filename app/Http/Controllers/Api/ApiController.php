<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared response shape for the whole API.
 *
 * Every response, success or failure, carries `success` and `message`, so a
 * caller reads one boolean rather than matching on status codes.
 */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, string $message = 'ok', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function fail(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     */
    protected function paged(LengthAwarePaginator $paginator, string $resource, string $message = 'ok'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Page size from ?per_page, clamped so one caller cannot ask for the whole
     * table in a single response.
     */
    protected function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', (string) config('outreach.api.page_size'));

        return max(1, min($requested, (int) config('outreach.api.max_page_size')));
    }
}
