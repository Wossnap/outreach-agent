<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ingest\ContactIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Contacts
 *
 * People we email, and everything that has passed between us and them.
 */
class ContactIngestController extends Controller
{
    public function store(Request $request, ContactIngestService $service): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048'],
            'custom' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:255'],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:255'],
        ]);

        $result = $service->ingest($payload);

        return response()->json([
            'success' => true,
            'message' => isset($result['skipped_reason']) && $result['skipped_reason'] === 'suppressed'
                ? 'Contact is suppressed; nothing enrolled.'
                : 'Contact ingested.',
            'data' => $result,
        ]);
    }
}
