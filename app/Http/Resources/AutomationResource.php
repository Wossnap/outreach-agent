<?php

namespace App\Http\Resources;

use App\Models\Automation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Automation */
class AutomationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The tag is what contact ingest matches on to enroll someone.
            'tag' => $this->tag,
            'name' => $this->name,
            'description' => $this->description,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'steps' => SequenceStepResource::collection($this->whenLoaded('steps')),
        ];
    }
}
