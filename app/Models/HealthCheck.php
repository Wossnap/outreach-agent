<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HealthCheck extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    protected $fillable = [
        'checkable_type', 'checkable_id', 'check_type', 'status', 'detail', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function checkable(): MorphTo
    {
        return $this->morphTo();
    }
}
