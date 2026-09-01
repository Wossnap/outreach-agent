<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActivityLog */
class ActivityLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            // info | warning | error
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context ?? [],
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'retryable' => (bool) $this->retryable,
            'retried_at' => $this->retried_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
