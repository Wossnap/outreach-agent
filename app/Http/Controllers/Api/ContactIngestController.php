<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushContactsRequest;
use App\Services\Ingest\ContactIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
     * Upserts the contact and enrolls them in the automation matching each tag.
     *
     * A contact does not have to arrive with an email address. Send whichever
     * you have: an email, a LinkedIn profile_url, or a name together with a
     * domain, and the address is found and checked for you. Until something has
     * confirmed an address, enrollments are created but held at
     * `waiting_email`, and they start on their own the moment it is confirmed.
     *
     * Submitting the same person again updates them rather than creating a
     * second record, so a caller that has lost track of what it already sent
     * can safely send it again. A suppressed address is stored and nothing is
     * enrolled.
     *
     * @bodyParam email string The contact's address. Example: jane.doe@acme.example
     * @bodyParam profile_url string A LinkedIn profile. Example: https://linkedin.com/in/jane-doe
     * @bodyParam first_name string Example: Jane
     * @bodyParam last_name string Example: Doe
     * @bodyParam name string The full name. Send this or the two parts; whichever you send wins, and the rest is filled in. Example: Jane Doe
     * @bodyParam company string Example: Acme Ltd
     * @bodyParam domain string The company's domain, which is what an address is searched by. A full URL is fine; it is reduced to the host. Example: acme.example
     * @bodyParam job_title string Example: Head of Operations
     * @bodyParam category string The lead's market category. Example: Home services
     * @bodyParam niche string The niche within that category. Example: Landscaping
     * @bodyParam company_url string The company's LinkedIn page. Example: https://linkedin.com/company/acme
     * @bodyParam source string Where the lead came from. Example: tube-trend-tool
     * @bodyParam extra object Anything else worth passing to the drafter. Filed under your source, so two callers can both send a "score" and mean different things. Example: {"industry": "SaaS"}
     * @bodyParam tags string[] Automation tags to enroll into. Example: ["seo-backlinks"]
     * @bodyParam contacts object[] Send this INSTEAD of every field above to push up to 500 people at once. Each entry takes exactly the same fields as a single push. A batch is all or nothing, so a caller never has to work out which half of it landed. Left out of the example below so the request is sendable as it stands. No-example
     */
    public function store(PushContactsRequest $request, ContactIngestService $service): JsonResponse
    {
        $results = [];

        /*
         * One transaction for the batch. After a partial import the caller
         * cannot tell what landed, and their only remedy is to send the whole
         * batch again.
         */
        DB::transaction(function () use ($request, $service, &$results): void {
            foreach ($request->contacts() as $contact) {
                $results[] = $service->ingest($contact);
            }
        });

        $created = count(array_filter($results, fn (array $r): bool => $r['created']));
        $suppressed = count(array_filter(
            $results,
            fn (array $r): bool => ($r['skipped_reason'] ?? null) === 'suppressed',
        ));

        /*
         * One answer shape, whether one person was sent or five hundred.
         *
         * `contacts` is always a list, so a caller reads the reply the same way
         * however it sent the request, and a list of one is a perfectly good
         * list.
         */
        return response()->json([
            'success' => true,
            'message' => $this->summarise(count($results), $suppressed),
            'data' => [
                'created' => $created,
                'updated' => count($results) - $created,
                'contacts' => $results,
            ],
        ]);
    }

    /** Says what happened, including the part a caller would otherwise miss. */
    private function summarise(int $total, int $suppressed): string
    {
        $message = $total.' contact'.($total === 1 ? '' : 's').' ingested.';

        // Storing somebody and enrolling nobody looks like success and is not
        // what the caller asked for, so the count is stated rather than left to
        // be inferred from an empty enrollments list.
        return $suppressed > 0
            ? $message.' '.$suppressed.' suppressed, so nothing was enrolled for them.'
            : $message;
    }
}
