<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STOPPED_REPLY = 'stopped_reply';

    public const STATUS_STOPPED_UNSUBSCRIBE = 'stopped_unsubscribe';

    public const STATUS_STOPPED_BOUNCE = 'stopped_bounce';

    public const STATUS_STOPPED_SUPPRESSED = 'stopped_suppressed';

    public const STATUS_STOPPED_REJECTED = 'stopped_rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'contact_id', 'automation_id', 'mailbox_id', 'status',
        'current_step', 'gmail_thread_id', 'stopped_at', 'stop_reason',
    ];

    protected function casts(): array
    {
        return ['stopped_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
