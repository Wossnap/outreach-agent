<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'website' => $this->website,
            'source' => $this->source,
            'custom' => $this->custom ?? [],
            'suppressed' => $this->whenNotNull($this->suppressed ?? null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'enrollments' => EnrollmentResource::collection($this->whenLoaded('enrollments')),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'replies' => ReplyResource::collection($this->whenLoaded('replies')),
        ];
    }
}
