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
    /**
     * List steps
     *
     * Every step of one automation, with the files attached to each.
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     */
    public function index(string $automation): JsonResponse
    {
        $model = $this->automation($automation);

        return $model
            ? $this->ok(SequenceStepResource::collection($model->steps)->resolve())
            : $this->fail('Automation not found.', 404);
    }

    /**
     * Add a step
     *
     * Position 1 is the first email; later positions are the follow-ups, sent
     * delay_days and delay_hours after the previous one.
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     *
     * @bodyParam position integer required 1 is the first email. Example: 2
     * @bodyParam delay_days integer Days after the previous step. Example: 3
     * @bodyParam delay_hours integer Hours after the previous step. Example: 0
     * @bodyParam drafting_instructions string required Example: Brief follow-up. Reference the first email, ask if they saw it.
     * @bodyParam active boolean Example: true
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

    /**
     * Update a step
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     * @urlParam step integer required Example: 1
     *
     * @bodyParam position integer Example: 2
     * @bodyParam delay_days integer Example: 3
     * @bodyParam delay_hours integer Example: 0
     * @bodyParam drafting_instructions string Example: Brief follow-up. Reference the first email, ask if they saw it.
     * @bodyParam active boolean Switch one step off without deleting it. Example: false
     */
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

    /**
     * Delete a step
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     * @urlParam step integer required Example: 1
     */
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
     * Attach a file to a step
     *
     * Send it as multipart/form-data under `file`.
     *
     * The file goes out with this step's email only. To attach the same file
     * to every email in the sequence, upload it to each step.
     *
     * Executables and archives are refused: recipients' gateways strip or
     * quarantine them, and that cost lands on the whole sending domain.
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     * @urlParam step integer required Example: 1
     *
     * @bodyParam file file required The file to attach. Choose it in Postman's Body tab.
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

    /**
     * Remove an attachment
     *
     * Deletes the file as well as the reference to it.
     *
     * @urlParam automation required An id or a tag. Example: seo-backlinks
     * @urlParam step integer required Example: 1
     * @urlParam attachment required The attachment id from the step's attachments array. Example: 9b1c7e2a4f6d4c8e9a0b1c2d3e4f5a6b
     */
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
