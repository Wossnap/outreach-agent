<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EnrollmentResource;
use App\Jobs\DraftEmailJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Services\Sending\EnrollmentStopper;
use App\Services\Sending\MailboxSelector;
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
     * List enrollments, newest first.
     *
     * Filters: ?status=, ?contact_id=, ?automation_id=, ?tag=, ?mailbox_id=.
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
     * Put an existing contact into an automation and start drafting step 1.
     *
     * POST /api/contacts is the route for new leads; this one is for a contact
     * already on file that should also go through another sequence.
     */
    public function store(Request $request, MailboxSelector $selector): JsonResponse
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

        $existing = Enrollment::query()
            ->where('contact_id', $contact->id)
            ->where('automation_id', $automation->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->first();

        if ($existing) {
            return $this->fail('That contact is already active in this automation.', 409);
        }

        $enrollment = Enrollment::query()->create([
            'contact_id' => $contact->id,
            'automation_id' => $automation->id,
            'mailbox_id' => $selector->select()?->id,
            'status' => Enrollment::STATUS_ACTIVE,
            'current_step' => 0,
        ]);

        DraftEmailJob::dispatch($enrollment->id, 1);

        return $this->ok(
            new EnrollmentResource($enrollment->load(['contact', 'automation', 'mailbox'])),
            'Enrolled; drafting the first email.',
            201,
        );
    }

    /**
     * Stop an enrollment. Anything already drafted or scheduled for it is
     * cancelled, so nothing further is sent.
     */
    public function destroy(Request $request, int $enrollment, EnrollmentStopper $stopper): JsonResponse
    {
        $model = Enrollment::query()->find($enrollment);

        if (! $model) {
            return $this->fail('Enrollment not found.', 404);
        }

        if (! $model->isActive()) {
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
