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
            'email_status' => $this->email_status,
            'email_checked_at' => $this->email_checked_at?->toIso8601String(),
            'email_provider' => $this->email_provider,
            'profile_url' => $this->profile_url,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'job_title' => $this->job_title,
            'company' => $this->company,
            'category' => $this->category,
            'niche' => $this->niche,
            'company_url' => $this->company_url,
            'domain' => $this->domain,
            'source' => $this->source,
            'extra' => $this->extra ?? [],
            'suppressed' => $this->whenNotNull($this->suppressed ?? null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'enrollments' => EnrollmentResource::collection($this->whenLoaded('enrollments')),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'replies' => ReplyResource::collection($this->whenLoaded('replies')),
        ];
    }
}
