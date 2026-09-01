<?php

namespace App\Http\Resources;

use App\Models\SequenceStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SequenceStep */
class SequenceStepResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'position' => $this->position,
            'delay_days' => $this->delay_days,
            'delay_hours' => $this->delay_hours,
            'drafting_instructions' => $this->drafting_instructions,
            'active' => (bool) $this->active,
            // The storage path is deliberately not exposed: it is an internal
            // location, and a caller has no use for it.
            'attachments' => collect($this->attachmentList())
                ->map(fn (array $a) => [
                    'id' => $a['id'],
                    'filename' => $a['filename'],
                    'mime' => $a['mime'],
                    'size' => $a['size'],
                ])
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
