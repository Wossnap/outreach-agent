<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_id', 'position', 'delay_days', 'delay_hours',
        'drafting_instructions', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function delayInHours(): int
    {
        return ($this->delay_days * 24) + $this->delay_hours;
    }
}
