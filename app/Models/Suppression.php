<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Suppression extends Model
{
    use HasFactory;

    public const REASON_UNSUBSCRIBED = 'unsubscribed';

    public const REASON_BOUNCED = 'bounced';

    public const REASON_MANUAL = 'manual';

    public const REASON_COMPLAINT = 'complaint';

    protected $fillable = ['email', 'reason', 'source_reply_id'];

    public static function suppress(string $email, string $reason, ?int $sourceReplyId = null): self
    {
        return static::query()->firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            ['reason' => $reason, 'source_reply_id' => $sourceReplyId],
        );
    }

    public static function isSuppressed(string $email): bool
    {
        return static::query()->where('email', mb_strtolower(trim($email)))->exists();
    }
}
