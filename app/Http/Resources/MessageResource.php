<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'contact_id' => $this->contact_id,
            'mailbox_id' => $this->mailbox_id,
            'mailbox_email' => $this->whenLoaded('mailbox', fn () => $this->mailbox?->email),
            'sequence_step_id' => $this->sequence_step_id,
            'step_position' => $this->whenLoaded('sequenceStep', fn () => $this->sequenceStep?->position),
            'status' => $this->status,
            'subject' => $this->subject,
            'body_text' => $this->body_text,
            'edited_by_user' => (bool) $this->edited_by_user,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_note' => $this->rejection_note,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'contact' => new ContactResource($this->whenLoaded('contact')),
        ];
    }
}
