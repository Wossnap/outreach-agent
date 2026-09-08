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

    /**
     * Enrolled, but with no address we are allowed to send to yet.
     *
     * People arrive without an email at all, and with addresses nothing has
     * confirmed. Neither can be drafted for,
     * but both should still be enrolled: the request said to put them in this
     * sequence, and dropping that on the floor would mean the caller has to
     * remember to ask again once the address turns up.
     *
     * So the enrollment is created with no mailbox and nothing drafted, and the
     * waterfall promotes it to active the moment the address is confirmed. See
     * EnrollmentActivator.
     */
    public const STATUS_WAITING_EMAIL = 'waiting_email';

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

    public function isWaitingForEmail(): bool
    {
        return $this->status === self::STATUS_WAITING_EMAIL;
    }

    /**
     * The statuses that still occupy a contact's place in an automation.
     *
     * A person waiting for an address is as enrolled as one being emailed, so
     * both block a second enrollment in the same automation. Used by the
     * partial unique index and by every "already enrolled" check, so the two
     * cannot disagree.
     *
     * @return array<int, string>
     */
    /**
     * What each status is called on screen.
     *
     * The stored values are machine-readable and read badly to a person:
     * "stopped_suppressed (Suppressed manually)" says the same thing twice in
     * two registers. The label says what happened and the reason says why.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_ACTIVE => 'Sending',
            self::STATUS_WAITING_EMAIL => 'Waiting for an address',
            self::STATUS_COMPLETED => 'Finished',
            self::STATUS_STOPPED_REPLY => 'Stopped, they replied',
            self::STATUS_STOPPED_UNSUBSCRIBE => 'Stopped, they opted out',
            self::STATUS_STOPPED_BOUNCE => 'Stopped, it bounced',
            self::STATUS_STOPPED_SUPPRESSED => 'Stopped, on the opt-out list',
            self::STATUS_STOPPED_REJECTED => 'Stopped, the draft was rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_FAILED => 'Failed',
        ];
    }

    /** This enrollment's status, as a person would say it. */
    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? str_replace('_', ' ', $this->status);
    }

    public static function openStatuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_WAITING_EMAIL];
    }
}
