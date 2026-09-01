<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\SuppressionResource;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Services\Sending\EnrollmentStopper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Suppressions
 *
 * The do-not-email list. Read it before pushing contacts.
 */
class SuppressionController extends ApiController
{
    /**
     * Everyone who must not be emailed.
     *
     * Worth reading before pushing contacts: ingest silently skips a
     * suppressed address, so a caller that does not check keeps offering
     * people who already opted out and never learns why nothing happens.
     *
     * Filters: ?reason=unsubscribed|bounced|manual|complaint, ?email=, ?since=.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Suppression::query()->latest('id');

        if ($reason = $request->query('reason')) {
            $query->where('reason', $reason);
        }

        if ($email = $request->query('email')) {
            $query->where('email', mb_strtolower(trim((string) $email)));
        }

        if ($since = $request->query('since')) {
            $query->where('created_at', '>=', $since);
        }

        return $this->paged($query->paginate($this->perPage($request)), SuppressionResource::class);
    }

    /**
     * Suppress an address and stop any sequence it is currently in.
     *
     * Suppressing without stopping the enrollment would leave already-drafted
     * emails queued behind it, so the address would still be written to.
     */
    public function store(Request $request, EnrollmentStopper $stopper): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'reason' => ['nullable', 'in:unsubscribed,bounced,manual,complaint'],
        ]);

        $suppression = Suppression::suppress(
            $payload['email'],
            $payload['reason'] ?? Suppression::REASON_MANUAL,
        );

        Enrollment::query()
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->whereHas('contact', fn ($q) => $q->where('email', $suppression->email))
            ->get()
            ->each(fn (Enrollment $e) => $stopper->stop(
                $e,
                Enrollment::STATUS_STOPPED_SUPPRESSED,
                'Suppressed via API',
            ));

        return $this->ok(new SuppressionResource($suppression), 'Address suppressed.', 201);
    }

    /**
     * Un-suppress an address, i.e. allow emailing someone who was on the
     * opt-out list. Disabled unless OUTREACH_API_ALLOW_SUPPRESSION_REMOVAL is
     * on; see the route definition.
     */
    public function destroy(string $email): JsonResponse
    {
        $deleted = Suppression::query()
            ->where('email', mb_strtolower(trim($email)))
            ->delete();

        return $deleted
            ? $this->ok(null, 'Address removed from the suppression list.')
            : $this->fail('That address is not suppressed.', 404);
    }
}
