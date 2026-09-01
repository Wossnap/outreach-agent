<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\Sending\MessageApprover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Messages
 *
 * Individual emails, from draft through to sent.
 */
class MessageController extends ApiController
{
    /**
     * List emails, oldest first, in the same order the approval queue shows.
     *
     * Filters: ?status= (pending_approval, approved, scheduled, sent,
     * rejected, failed, ...), ?contact_id=, ?enrollment_id=, ?mailbox_id=,
     * ?since= (ISO date).
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

    public function show(int $message): JsonResponse
    {
        $model = Message::query()->with(['contact', 'mailbox', 'sequenceStep'])->find($message);

        return $model
            ? $this->ok(new MessageResource($model))
            : $this->fail('Message not found.', 404);
    }

    /**
     * Edit a draft's subject or body before it is approved.
     *
     * Only while it is awaiting approval: once approved it has a send slot,
     * and a later edit would change an email that is on its way out.
     */
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
     * Approve a draft so it sends.
     *
     * Disabled unless OUTREACH_API_ALLOW_APPROVAL is on, because approving is
     * the point where a human normally reads the email before a real person
     * receives it. See the route definition.
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
     * Reject a draft. This also stops the contact's sequence, the same as
     * rejecting in the dashboard does.
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
