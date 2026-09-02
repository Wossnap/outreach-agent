<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Automations
 *
 * A sequence of emails, identified by the tag that enrolls contacts into it.
 */
class AutomationController extends ApiController
{
    /**
     * List automations
     *
     * Each with its steps.
     *
     * @queryParam active boolean Only switched-on or switched-off automations. e.g. true. No-example
     * @queryParam tag string Exact tag. e.g. seo-backlinks. No-example
     * @queryParam per_page integer Rows per page. Clamped to 200. e.g. 50. No-example
     * @queryParam page integer Which page to return. e.g. 1. No-example
     */
    public function index(Request $request): JsonResponse
    {
        $query = Automation::query()->with('steps')->latest('id');

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($tag = $request->query('tag')) {
            $query->where('tag', $tag);
        }

        return $this->paged($query->paginate($this->perPage($request)), AutomationResource::class);
    }

    /**
     * Get one automation
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     */
    public function show(string $automation): JsonResponse
    {
        $model = $this->resolve($automation);

        return $model
            ? $this->ok(new AutomationResource($model->load('steps')))
            : $this->fail('Automation not found.', 404);
    }

    /**
     * Create an automation
     *
     * Optionally with its steps in the same call.
     *
     * The tag is how contacts get enrolled: pushing a contact with that tag to
     * POST /api/contacts starts them on this sequence.
     *
     * @bodyParam tag string required Example: seo-backlinks
     * @bodyParam name string required Example: SEO backlinks outreach
     * @bodyParam description string Example: Two touches for sites that could carry a link back to us.
     * @bodyParam active boolean Defaults to true. Example: true
     * @bodyParam steps object[] The sequence, optional here and addable later.
     * @bodyParam steps[].position integer required 1 is the first email. Example: 1
     * @bodyParam steps[].delay_days integer Days after the previous step. Example: 0
     * @bodyParam steps[].delay_hours integer Hours after the previous step. Example: 0
     * @bodyParam steps[].drafting_instructions string required What Claude should write. Example: Short, friendly cold email asking if they would consider a guest post. Two sentences, no pitch.
     * @bodyParam steps[].active boolean Example: true
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tag' => ['required', 'string', 'max:255', 'unique:automations,tag'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array'],
            'steps.*.position' => ['required', 'integer', 'min:1'],
            'steps.*.delay_days' => ['nullable', 'integer', 'min:0'],
            'steps.*.delay_hours' => ['nullable', 'integer', 'min:0'],
            'steps.*.drafting_instructions' => ['required', 'string'],
            'steps.*.active' => ['nullable', 'boolean'],
        ]);

        $automation = Automation::query()->create([
            'tag' => $payload['tag'],
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'active' => $payload['active'] ?? true,
        ]);

        foreach ($payload['steps'] ?? [] as $step) {
            $automation->steps()->create($step + ['active' => $step['active'] ?? true]);
        }

        return $this->ok(new AutomationResource($automation->load('steps')), 'Automation created.', 201);
    }

    /**
     * Update an automation
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     *
     * @bodyParam tag string Example: seo-backlinks
     * @bodyParam name string Example: SEO backlinks outreach
     * @bodyParam description string Example: Two touches for sites that could carry a link back to us.
     * @bodyParam active boolean Switch the whole sequence off without deleting it. Example: false
     */
    public function update(Request $request, string $automation): JsonResponse
    {
        $model = $this->resolve($automation);

        if (! $model) {
            return $this->fail('Automation not found.', 404);
        }

        $payload = $request->validate([
            'tag' => ['sometimes', 'string', 'max:255', Rule::unique('automations', 'tag')->ignore($model->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $model->update($payload);

        return $this->ok(new AutomationResource($model->fresh()->load('steps')), 'Automation updated.');
    }

    protected function resolve(string $identifier): ?Automation
    {
        return ctype_digit($identifier)
            ? Automation::query()->find((int) $identifier)
            : Automation::query()->where('tag', $identifier)->first();
    }
}
