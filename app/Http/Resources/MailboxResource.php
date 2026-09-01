<?php

namespace App\Http\Resources;

use App\Models\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Google tokens are never included: they are send-capable credentials for a
 * real inbox, and no API caller needs them.
 *
 * @mixin Mailbox
 */
class MailboxResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'domain' => $this->whenLoaded('domain', fn () => $this->domain?->name),
            // active | paused | disconnected | error
            'status' => $this->status,
            'paused_reason' => $this->paused_reason,
            'health_status' => $this->health_status,
            'daily_cap' => $this->daily_cap,
            'send_window' => [
                'start' => $this->send_window_start,
                'end' => $this->send_window_end,
                'timezone' => $this->send_timezone,
                'weekends' => (bool) $this->send_weekends,
            ],
            'warmup_enabled' => (bool) $this->warmup_enabled,
            'stats_7d' => [
                'sent' => $this->sent_7d,
                'bounce_rate' => (float) $this->bounce_rate_7d,
                'reply_rate' => (float) $this->reply_rate_7d,
            ],
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'last_send_error' => $this->last_send_error,
        ];
    }
}
