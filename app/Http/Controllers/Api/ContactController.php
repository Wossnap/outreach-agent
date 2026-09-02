<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Contacts
 *
 * People we email, and everything that has passed between us and them.
 */
class ContactController extends ApiController
{
    /**
     * List contacts
     *
     * Newest first.
     *
     * @queryParam email string Exact address. e.g. jane.doe@acme.example. No-example
     * @queryParam company string Partial match on company. e.g. Acme. No-example
     * @queryParam q string Partial match on email, either name, or company. e.g. acme. No-example
     * @queryParam tag string Only contacts enrolled in this automation. e.g. seo-backlinks. No-example
     * @queryParam status string Only contacts with an enrollment in this state. e.g. active. No-example
     * @queryParam suppressed boolean true for opted-out contacts only, false to exclude them. e.g. true. No-example
     * @queryParam since string ISO date. Only rows created on or after it. e.g. 2026-08-01. No-example
     * @queryParam per_page integer Rows per page. Clamped to 200. e.g. 50. No-example
     * @queryParam page integer Which page to return. e.g. 1. No-example
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()->latest('id');

        if ($email = $request->query('email')) {
            $query->where('email', mb_strtolower(trim((string) $email)));
        }

        if ($company = $request->query('company')) {
            $query->where('company', 'like', '%'.$company.'%');
        }

        if ($term = $request->query('q')) {
            $query->where(function ($q) use ($term) {
                foreach (['email', 'name', 'first_name', 'last_name', 'company'] as $column) {
                    $q->orWhere($column, 'like', '%'.$term.'%');
                }
            });
        }

        if ($tag = $request->query('tag')) {
            $query->whereHas('enrollments.automation', fn ($q) => $q->where('tag', $tag));
        }

        if ($status = $request->query('status')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('status', $status));
        }

        if ($request->filled('suppressed')) {
            $suppressed = $request->boolean('suppressed');
            $emails = Suppression::query()->select('email');

            $suppressed
                ? $query->whereIn('email', $emails)
                : $query->whereNotIn('email', $emails);
        }

        if ($since = $request->query('since')) {
            $query->where('created_at', '>=', $since);
        }

        return $this->paged($query->paginate($this->perPage($request)), ContactResource::class);
    }

    /**
     * Get one contact
     *
     * The contact with its enrollments, every email sent to them and every
     * reply received, in one request.
     *
     * @urlParam contact required An id or an email address. Example: jane.doe@acme.example
     */
    public function show(string $contact): JsonResponse
    {
        $model = $this->resolve($contact);

        if (! $model) {
            return $this->fail('Contact not found.', 404);
        }

        $model->load([
            'enrollments.automation',
            'enrollments.mailbox',
            'messages.sequenceStep',
            'messages.mailbox',
            'replies',
        ]);

        $model->suppressed = $model->isSuppressed();

        return $this->ok(new ContactResource($model));
    }

    /**
     * Contacts are addressed by id or by email, since a caller pushing leads
     * knows the address but not our id.
     */
    protected function resolve(string $identifier): ?Contact
    {
        return ctype_digit($identifier)
            ? Contact::query()->find((int) $identifier)
            : Contact::query()->where('email', mb_strtolower(trim($identifier)))->first();
    }
}
