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
     * List mailboxes
     *
     * The connected sending accounts with their health and 7-day stats.
     * Google tokens are never included.
     *
     * There is no endpoint to add one: connecting a mailbox needs a human at
     * Google's own consent screen, in the dashboard.
     *
     * @queryParam status string One of active, paused, disconnected, error. Example: active
     * @queryParam per_page integer Rows per page. Clamped to 200. Example: 50
     * @queryParam page integer Which page to return. Example: 1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Mailbox::query()->with('domain')->orderBy('email');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paged($query->paginate($this->perPage($request)), MailboxResource::class);
    }

    /**
     * Get one mailbox
     *
     * @urlParam mailbox integer required Example: 1
     */
    public function show(int $mailbox): JsonResponse
    {
        $model = Mailbox::query()->with('domain')->find($mailbox);

        return $model
            ? $this->ok(new MailboxResource($model))
            : $this->fail('Mailbox not found.', 404);
    }

    /**
     * Pause a mailbox
     *
     * Stops it sending. Anything already scheduled on it stays queued.
     *
     * @urlParam mailbox integer required Example: 1
     *
     * @bodyParam reason string Shown on the Mailboxes page. Example: Paused while we check deliverability
     */
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
     * Resume a mailbox
     *
     * A disconnected mailbox cannot be resumed here: its Google token is gone,
     * and flipping the status would only queue sends that fail. Reconnect it
     * in the dashboard instead.
     *
     * @urlParam mailbox integer required Example: 1
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
