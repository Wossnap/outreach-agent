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
    /**
     * Push a contact
     *
     * Upserts the contact and enrolls them in the automation matching each
     * tag. A suppressed address is accepted and nothing is enrolled, so read
     * GET /api/suppressions first if you need to know that happened.
     *
     * @bodyParam email string required The contact's address. Example: jane.doe@acme.example
     * @bodyParam first_name string Example: Jane
     * @bodyParam last_name string Example: Doe
     * @bodyParam name string The full name. Send this or the two parts; whichever you send wins, and the rest is filled in. Example: Jane Doe
     * @bodyParam company string Example: Acme Ltd
     * @bodyParam website string Example: https://acme.example
     * @bodyParam source string Where the lead came from. Example: tube-trend-tool
     * @bodyParam custom object Anything else worth passing to the drafter. Example: {"industry": "SaaS"}
     * @bodyParam tags string[] required One or more automation tags to enroll into. Example: ["seo-backlinks"]
     */
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
