<?php

namespace App\Http\Resources;

use App\Models\Suppression;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Suppression */
class SuppressionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            // unsubscribed | bounced | manual | complaint
            'reason' => $this->reason,
            'source_reply_id' => $this->source_reply_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
