<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reply extends Model
{
    use HasFactory;

    public const CLASS_REPLY = 'reply';

    public const CLASS_BOUNCE = 'bounce';

    public const CLASS_AUTO_REPLY = 'auto_reply';

    public const CLASS_UNSUBSCRIBE = 'unsubscribe';

    protected $fillable = [
        'mailbox_id', 'enrollment_id', 'contact_id', 'message_id',
        'gmail_message_id', 'gmail_thread_id', 'from_email', 'subject',
        'snippet', 'body_text', 'classification', 'received_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
