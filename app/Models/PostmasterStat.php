<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of Gmail Postmaster Tools figures for a sending domain.
 *
 * Rates are fractions of mail Gmail delivered from the domain that day. The
 * spam rate is the share Gmail users marked as spam, which is the only view of
 * "went to spam" anyone outside Google gets.
 */
class PostmasterStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id', 'date', 'spam_rate', 'auth_success_rate', 'delivery_error_rate', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spam_rate' => 'float',
            'auth_success_rate' => 'float',
            'delivery_error_rate' => 'float',
            'raw' => 'array',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
