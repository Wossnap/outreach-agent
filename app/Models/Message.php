<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    public const STATUS_DRAFTING = 'drafting';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DRAFT_FAILED = 'draft_failed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /** Statuses that still lead to a send (cancellable when an enrollment stops). */
    public const CANCELLABLE_STATUSES = [
        self::STATUS_DRAFTING,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_SCHEDULED,
    ];

    protected $fillable = [
        'enrollment_id', 'sequence_step_id', 'mailbox_id', 'contact_id', 'status',
        'subject', 'body_text', 'ai_subject', 'ai_body', 'edited_by_user',
        'scheduled_at', 'sending_started_at', 'sent_at',
        'gmail_message_id', 'gmail_thread_id', 'rfc_message_id',
        'error', 'attempts', 'approved_at', 'rejected_at', 'rejection_note',
    ];

    protected function casts(): array
    {
        return [
            'edited_by_user' => 'boolean',
            'scheduled_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function sequenceStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isFollowUp(): bool
    {
        return $this->sequenceStep && $this->sequenceStep->position > 1;
    }
}
