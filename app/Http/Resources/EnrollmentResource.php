<?php

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Enrollment */
class EnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'automation_id' => $this->automation_id,
            'automation_tag' => $this->whenLoaded('automation', fn () => $this->automation->tag),
            'mailbox_id' => $this->mailbox_id,
            'mailbox_email' => $this->whenLoaded('mailbox', fn () => $this->mailbox?->email),
            'status' => $this->status,
            'current_step' => $this->current_step,
            'stopped_at' => $this->stopped_at?->toIso8601String(),
            'stop_reason' => $this->stop_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'contact' => new ContactResource($this->whenLoaded('contact')),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
