<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\Sending\MessageApprover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\BodyParam;

/**
 * @group Messages
 *
 * Individual emails, from draft through to sent.
 */
class MessageController extends ApiController
{
    /**
     * List emails
     *
     * Oldest first, the same order the approval queue shows.
     *
     * @queryParam status string One of drafting, pending_approval, approved, scheduled, sending, sent, draft_failed, rejected, cancelled, failed. Example: pending_approval
     * @queryParam contact_id integer Example: 1
     * @queryParam enrollment_id integer Example: 1
     * @queryParam mailbox_id integer Example: 1
     * @queryParam since string ISO date. Only rows created on or after it. Example: 2026-08-01
     * @queryParam per_page integer Rows per page. Clamped to 200. Example: 50
     * @queryParam page integer Which page to return. Example: 1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Message::query()
            ->with(['contact', 'mailbox', 'sequenceStep'])
            ->oldest('created_at')
            // created_at is second-precision, so drafts written in the same
            // second tie. Without the id the page order is not stable and a
            // caller paging through can see a row twice or not at all.
            ->orderBy('id');

        foreach (['status', 'contact_id', 'enrollment_id', 'mailbox_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($since = $request->query('since')) {
            $query->where('created_at', '>=', $since);
        }

        return $this->paged($query->paginate($this->perPage($request)), MessageResource::class);
    }

    /**
     * Get one email
     *
     * @urlParam message integer required Example: 1
     */
    public function show(int $message): JsonResponse
    {
        $model = Message::query()->with(['contact', 'mailbox', 'sequenceStep'])->find($message);

        return $model
            ? $this->ok(new MessageResource($model))
            : $this->fail('Message not found.', 404);
    }

    /**
     * Edit a draft
     *
     * Changes the subject or body before the draft is approved.
     *
     * Only while it is awaiting approval: once approved it has a send slot,
     * and a later edit would change an email that is already on its way out.
     *
     * @urlParam message integer required Example: 1
     *
     * @bodyParam subject string Example: Quick thought on your resources page
     */
    // A docblock's `Example:` ends at the newline, so it cannot carry one. The
    // attribute takes a real PHP string, which JSON-encodes to a proper \n and
    // arrives as an actual line break.
    #[BodyParam('body_text', 'string', 'The plain-text body, stored and sent exactly as given. Line breaks are ordinary JSON newlines.', required: false, example: "Hi Jane,\n\nRewriting this before it goes out.\n\nAlex")]
    public function update(Request $request, int $message): JsonResponse
    {
        $model = Message::query()->find($message);

        if (! $model) {
            return $this->fail('Message not found.', 404);
        }

        if ($model->status !== Message::STATUS_PENDING_APPROVAL) {
            return $this->fail('Only a draft awaiting approval can be edited.', 409);
        }

        $payload = $request->validate([
            'subject' => ['sometimes', 'string', 'max:998'],
            'body_text' => ['sometimes', 'string'],
        ]);

        if ($payload === []) {
            return $this->fail('Send subject, body_text, or both.', 422);
        }

        $model->update($payload + ['edited_by_user' => true]);

        return $this->ok(new MessageResource($model->fresh()), 'Draft updated.');
    }

    /**
     * Approve a draft
     *
     * Approves the draft and gives it a send slot.
     *
     * Returns 403 unless an administrator has set
     * OUTREACH_API_ALLOW_APPROVAL=true, whatever abilities the key carries.
     * Approving is the point where a human normally reads the email before a
     * real person receives it, so a key cannot do it by default.
     *
     * A success with no send time is not an error: it means no mailbox was
     * sendable at that moment, and the reconciler picks it up later.
     *
     * @urlParam message integer required Example: 1
     */
    public function approve(int $message, MessageApprover $approver): JsonResponse
    {
        $model = Message::query()->with('enrollment')->find($message);

        if (! $model) {
            return $this->fail('Message not found.', 404);
        }

        $result = $approver->approve($model);

        if (! $result['approved']) {
            return $this->fail('Only a draft awaiting approval can be approved.', 409);
        }

        return $this->ok(
            new MessageResource($model->fresh()),
            $result['scheduled_at'] !== null
                ? 'Approved and scheduled.'
                : 'Approved, but no sendable mailbox is connected, so it is not scheduled yet.',
        );
    }

    /**
     * Reject a draft
     *
     * Also stops the contact's sequence, the same as rejecting in the
     * dashboard does. Without that the enrollment would sit active with
     * nothing left to advance it.
     *
     * @urlParam message integer required Example: 1
     *
     * @bodyParam note string Why it was rejected. Stored on the message and the stopped enrollment. Example: Wrong angle for this one
     */
    public function reject(Request $request, int $message, MessageApprover $approver): JsonResponse
    {
        $model = Message::query()->with('enrollment')->find($message);

        if (! $model) {
            return $this->fail('Message not found.', 404);
        }

        $payload = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        if (! $approver->reject($model, $payload['note'] ?? null)) {
            return $this->fail('Only a draft awaiting approval can be rejected.', 409);
        }

        return $this->ok(new MessageResource($model->fresh()), 'Draft rejected and the sequence stopped.');
    }
}
