<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MailboxResource;
use App\Models\Mailbox;
use App\Services\Gmail\MailboxDisconnector;
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
     * @queryParam status string One of active, paused, disconnected, error. e.g. active. No-example
     * @queryParam per_page integer Rows per page. Clamped to 200. e.g. 50. No-example
     * @queryParam page integer Which page to return. e.g. 1. No-example
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

    /**
     * Disconnect a mailbox
     *
     * Tells Google to forget this application, discards the stored
     * credentials, and stops the mailbox sending. Stronger than pausing: a
     * paused mailbox keeps its connection and resumes with one call, whereas a
     * disconnected one needs a person to sign in at Google again.
     *
     * Anything queued to send through it is released and picked up by another
     * mailbox. Emails already handed to Gmail still go out.
     *
     * The history is kept, so reconnecting the same address reuses this
     * mailbox rather than creating a second one.
     *
     * @urlParam mailbox integer required Example: 1
     *
     * The example below is deliberately a placeholder rather than a plausible
     * reason: this request signs a real account out, and a ready-to-send body
     * makes that one careless click away.
     *
     * @bodyParam reason string Recorded against the mailbox and in the activity log. Example: Reason for disconnecting
     */
    public function disconnect(Request $request, int $mailbox, MailboxDisconnector $disconnector): JsonResponse
    {
        $model = Mailbox::query()->find($mailbox);

        if (! $model) {
            return $this->fail('Mailbox not found.', 404);
        }

        if ($model->status === Mailbox::STATUS_DISCONNECTED) {
            return $this->fail('That mailbox is already disconnected.', 409);
        }

        $payload = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $result = $disconnector->disconnect($model, $payload['reason'] ?? 'Disconnected via API');

        return $this->ok(
            new MailboxResource($model->fresh()->load('domain')),
            $result['released'] > 0
                ? "Mailbox disconnected. {$result['released']} queued email(s) released for another mailbox."
                : 'Mailbox disconnected.',
        );
    }
}
