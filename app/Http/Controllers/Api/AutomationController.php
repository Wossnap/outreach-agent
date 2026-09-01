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
    /** List automations with their steps. Filters: ?active=true|false, ?tag=. */
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

    /** One automation with its steps. Accepts an id or a tag. */
    public function show(string $automation): JsonResponse
    {
        $model = $this->resolve($automation);

        return $model
            ? $this->ok(new AutomationResource($model->load('steps')))
            : $this->fail('Automation not found.', 404);
    }

    /**
     * Create an automation, optionally with its steps in the same call.
     *
     * The tag is how contacts get enrolled: pushing a contact with that tag to
     * POST /api/contacts starts them on this sequence.
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
