<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MailboxResource;
use App\Models\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Mailboxes
 *
 * The Gmail accounts that send. Connected in the dashboard, managed here.
 */
class MailboxController extends ApiController
{
    /**
     * Connected sending mailboxes with their health and 7-day stats.
     *
     * Mailboxes are connected in the dashboard through Google sign-in, so
     * there is no endpoint to create one: it needs a human at a Google
     * consent screen.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Mailbox::query()->with('domain')->orderBy('email');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paged($query->paginate($this->perPage($request)), MailboxResource::class);
    }

    public function show(int $mailbox): JsonResponse
    {
        $model = Mailbox::query()->with('domain')->find($mailbox);

        return $model
            ? $this->ok(new MailboxResource($model))
            : $this->fail('Mailbox not found.', 404);
    }

    /** Stop this mailbox sending. Anything already scheduled on it stays queued. */
    public function pause(Request $request, int $mailbox): JsonResponse
    {
        $model = Mailbox::query()->find($mailbox);

        if (! $model) {
            return $this->fail('Mailbox not found.', 404);
        }

        $payload = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $model->update([
            'status' => Mailbox::STATUS_PAUSED,
            'paused_reason' => $payload['reason'] ?? 'Paused via API',
        ]);

        return $this->ok(new MailboxResource($model->fresh()->load('domain')), 'Mailbox paused.');
    }

    /**
     * Resume sending.
     *
     * A disconnected mailbox cannot be resumed here: its Google token is gone
     * and only re-connecting in the dashboard restores it.
     */
    public function resume(int $mailbox): JsonResponse
    {
        $model = Mailbox::query()->find($mailbox);

        if (! $model) {
            return $this->fail('Mailbox not found.', 404);
        }

        if ($model->status === Mailbox::STATUS_DISCONNECTED) {
            return $this->fail('This mailbox is disconnected; reconnect it in the dashboard.', 409);
        }

        $model->update(['status' => Mailbox::STATUS_ACTIVE, 'paused_reason' => null]);

        return $this->ok(new MailboxResource($model->fresh()->load('domain')), 'Mailbox resumed.');
    }
}
