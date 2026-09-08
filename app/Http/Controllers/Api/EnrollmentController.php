<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EnrollmentResource;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Services\Sending\EnrollmentActivator;
use App\Services\Sending\EnrollmentStopper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Enrollments
 *
 * A contact moving through an automation.
 */
class EnrollmentController extends ApiController
{
    /**
     * List enrollments
     *
     * Newest first.
     *
     * @queryParam status string One of active, waiting_email, completed, stopped_reply, stopped_unsubscribe, stopped_bounce, stopped_suppressed, stopped_rejected, cancelled, failed. `waiting_email` means enrolled, with no confirmed address to send to yet. e.g. active. No-example
     * @queryParam contact_id integer e.g. 1. No-example
     * @queryParam automation_id integer e.g. 1. No-example
     * @queryParam tag string Automation tag. e.g. seo-backlinks. No-example
     * @queryParam mailbox_id integer e.g. 1. No-example
     * @queryParam per_page integer Rows per page. Clamped to 200. e.g. 50. No-example
     * @queryParam page integer Which page to return. e.g. 1. No-example
     */
    public function index(Request $request): JsonResponse
    {
        $query = Enrollment::query()
            ->with(['contact', 'automation', 'mailbox'])
            ->latest('id');

        foreach (['status', 'contact_id', 'automation_id', 'mailbox_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($tag = $request->query('tag')) {
            $query->whereHas('automation', fn ($q) => $q->where('tag', $tag));
        }

        return $this->paged($query->paginate($this->perPage($request)), EnrollmentResource::class);
    }

    /**
     * Enroll a contact
     *
     * Puts a contact already on file into an automation and starts drafting
     * step 1. POST /api/contacts is the route for new leads; this one is for
     * someone who should also go through another sequence.
     *
     * Identify the contact by contact_id or email, and the automation by
     * automation_id or tag.
     *
     * @bodyParam email string The contact's address. Send this or contact_id. Example: jane.doe@acme.example
     * @bodyParam tag string The automation's tag. Send this or automation_id. Example: seo-backlinks
     * @bodyParam contact_id integer Alternative to email. Left out of the example below so the request is sendable as it stands. No-example
     * @bodyParam automation_id integer Alternative to tag. Left out of the example below so the request is sendable as it stands. No-example
     */
    public function store(Request $request, EnrollmentActivator $activator): JsonResponse
    {
        $payload = $request->validate([
            'contact_id' => ['required_without:email', 'integer', 'exists:contacts,id'],
            'email' => ['required_without:contact_id', 'email'],
            'tag' => ['required_without:automation_id', 'string'],
            'automation_id' => ['required_without:tag', 'integer', 'exists:automations,id'],
        ]);

        $contact = isset($payload['contact_id'])
            ? Contact::query()->find($payload['contact_id'])
            : Contact::query()->where('email', mb_strtolower(trim($payload['email'])))->first();

        if (! $contact) {
            return $this->fail('Contact not found. Push it to POST /api/contacts first.', 404);
        }

        if (Suppression::isSuppressed($contact->email)) {
            return $this->fail('That contact is suppressed and cannot be enrolled.', 409);
        }

        $automation = isset($payload['automation_id'])
            ? Automation::query()->find($payload['automation_id'])
            : Automation::query()->where('tag', $payload['tag'])->first();

        if (! $automation) {
            return $this->fail('Automation not found.', 404);
        }

        if (! $automation->active) {
            return $this->fail('That automation is switched off.', 409);
        }

        if ($activator->hasOpenEnrollment($contact, $automation)) {
            return $this->fail('That contact is already active in this automation.', 409);
        }

        $enrollment = $activator->enroll($contact, $automation);

        return $this->ok(
            new EnrollmentResource($enrollment->load(['contact', 'automation', 'mailbox'])),
            $enrollment->isActive()
                ? 'Enrolled; drafting the first email.'
                : 'Enrolled, and waiting for a confirmed email address before anything is drafted.',
            201,
        );
    }

    /**
     * Stop an enrollment
     *
     * Anything already drafted or scheduled for it is cancelled, so nothing
     * further is sent.
     *
     * @urlParam enrollment integer required Example: 1
     *
     * @bodyParam reason string Recorded against the stopped enrollment. Example: Replied on another channel
     */
    public function destroy(Request $request, int $enrollment, EnrollmentStopper $stopper): JsonResponse
    {
        $model = Enrollment::query()->find($enrollment);

        if (! $model) {
            return $this->fail('Enrollment not found.', 404);
        }

        // Waiting counts as open, so it can be called off like any other: a
        // caller who has changed their mind about somebody should not have to
        // wait for an address to turn up before they can say so.
        if (! in_array($model->status, Enrollment::openStatuses(), true)) {
            return $this->fail('That enrollment is already stopped.', 409);
        }

        $payload = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $stopper->stop(
            $model,
            Enrollment::STATUS_CANCELLED,
            $payload['reason'] ?? 'Stopped via API',
        );

        return $this->ok(new EnrollmentResource($model->fresh()), 'Enrollment stopped.');
    }
}
