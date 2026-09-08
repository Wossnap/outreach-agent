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

    /**
     * Add an address to the never-email list.
     *
     * Null is accepted and does nothing, because a contact does not have to
     * have an address. This list is keyed on the address, so there is nothing
     * to record for somebody we can only name. Whether that person should be
     * emailed is decided by the enrollment, which is stopped separately.
     */
    public static function suppress(?string $email, string $reason, ?int $sourceReplyId = null): ?self
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        return static::query()->firstOrCreate(
            ['email' => $email],
            ['reason' => $reason, 'source_reply_id' => $sourceReplyId],
        );
    }

    /** Nobody with no address is on a list of addresses. */
    public static function isSuppressed(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && static::query()->where('email', $email)->exists();
    }
}
