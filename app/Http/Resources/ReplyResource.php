<?php

namespace App\Http\Resources;

use App\Models\Reply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reply */
class ReplyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'enrollment_id' => $this->enrollment_id,
            'message_id' => $this->message_id,
            'mailbox_id' => $this->mailbox_id,
            'from_email' => $this->from_email,
            'subject' => $this->subject,
            'snippet' => $this->snippet,
            'body_text' => $this->body_text,
            // reply | bounce | auto_reply | unsubscribe
            'classification' => $this->classification,
            'received_at' => $this->received_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'contact' => new ContactResource($this->whenLoaded('contact')),
        ];
    }
}
