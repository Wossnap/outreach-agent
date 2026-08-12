<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    protected $fillable = [
        'subject_type', 'subject_id', 'event', 'level', 'message',
        'context', 'retryable', 'retried_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'retryable' => 'boolean',
            'retried_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(
        string $event,
        string $message,
        string $level = self::LEVEL_INFO,
        ?Model $subject = null,
        array $context = [],
        bool $retryable = false,
    ): self {
        return static::query()->create([
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'level' => $level,
            'message' => $message,
            'context' => $context ?: null,
            'retryable' => $retryable,
        ]);
    }
}
