<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\SequenceStepResource;
use App\Models\Automation;
use App\Models\SequenceStep;
use App\Services\Attachments\StepAttachmentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * @group Automations
 *
 * A sequence of emails, identified by the tag that enrolls contacts into it.
 */
class SequenceStepController extends ApiController
{
    public function index(string $automation): JsonResponse
    {
        $model = $this->automation($automation);

        return $model
            ? $this->ok(SequenceStepResource::collection($model->steps)->resolve())
            : $this->fail('Automation not found.', 404);
    }

    /**
     * Add a step. Position 1 is the first email; later positions are the
     * follow-ups, sent delay_days/delay_hours after the previous one.
     */
    public function store(Request $request, string $automation): JsonResponse
    {
        $model = $this->automation($automation);

        if (! $model) {
            return $this->fail('Automation not found.', 404);
        }

        $payload = $request->validate([
            'position' => [
                'required', 'integer', 'min:1',
                Rule::unique('sequence_steps', 'position')->where('automation_id', $model->id),
            ],
            'delay_days' => ['nullable', 'integer', 'min:0'],
            'delay_hours' => ['nullable', 'integer', 'min:0'],
            'drafting_instructions' => ['required', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $step = $model->steps()->create($payload + ['active' => $payload['active'] ?? true]);

        return $this->ok(new SequenceStepResource($step), 'Step created.', 201);
    }

    public function update(Request $request, string $automation, int $step): JsonResponse
    {
        $model = $this->step($automation, $step);

        if (! $model) {
            return $this->fail('Step not found.', 404);
        }

        $payload = $request->validate([
            'position' => [
                'sometimes', 'integer', 'min:1',
                Rule::unique('sequence_steps', 'position')
                    ->where('automation_id', $model->automation_id)
                    ->ignore($model->id),
            ],
            'delay_days' => ['sometimes', 'integer', 'min:0'],
            'delay_hours' => ['sometimes', 'integer', 'min:0'],
            'drafting_instructions' => ['sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $model->update($payload);

        return $this->ok(new SequenceStepResource($model->fresh()), 'Step updated.');
    }

    public function destroy(string $automation, int $step): JsonResponse
    {
        $model = $this->step($automation, $step);

        if (! $model) {
            return $this->fail('Step not found.', 404);
        }

        $model->delete();

        return $this->ok(null, 'Step deleted.');
    }

    /**
     * Attach a file to this step. Send it as multipart/form-data under `file`.
     *
     * The file goes out with this step's email only. To attach the same file
     * to every email in the sequence, upload it to each step.
     */
    public function storeAttachment(Request $request, string $automation, int $step, StepAttachmentStore $store): JsonResponse
    {
        $model = $this->step($automation, $step);

        if (! $model) {
            return $this->fail('Step not found.', 404);
        }

        $request->validate(['file' => ['required', ...StepAttachmentStore::rules()]]);

        try {
            $store->add($model, $request->file('file'));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(new SequenceStepResource($model->fresh()), 'Attachment added.', 201);
    }

    public function destroyAttachment(string $automation, int $step, string $attachment, StepAttachmentStore $store): JsonResponse
    {
        $model = $this->step($automation, $step);

        if (! $model) {
            return $this->fail('Step not found.', 404);
        }

        if (! $store->remove($model, $attachment)) {
            return $this->fail('Attachment not found on this step.', 404);
        }

        return $this->ok(new SequenceStepResource($model->fresh()), 'Attachment removed.');
    }

    protected function automation(string $identifier): ?Automation
    {
        return ctype_digit($identifier)
            ? Automation::query()->find((int) $identifier)
            : Automation::query()->where('tag', $identifier)->first();
    }

    protected function step(string $automation, int $step): ?SequenceStep
    {
        return $this->automation($automation)
            ?->steps()
            ->whereKey($step)
            ->first();
    }
}
