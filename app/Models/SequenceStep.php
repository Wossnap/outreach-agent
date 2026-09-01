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
        'drafting_instructions', 'attachments', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'attachments' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * Files attached to this step's email, with defaults filled in for rows
     * written before a key existed. Whether the file is still on disk is not
     * checked here; the sender fails loudly if one has gone.
     *
     * @return array<int, array{id: string, disk: string, path: string, filename: string, mime: string, size: int}>
     */
    public function attachmentList(): array
    {
        return collect($this->attachments ?? [])
            ->filter(fn ($a) => is_array($a) && filled($a['path'] ?? null))
            ->map(fn (array $a) => [
                'id' => (string) ($a['id'] ?? md5($a['path'])),
                'disk' => $a['disk'] ?? config('outreach.attachments.disk'),
                'path' => $a['path'],
                'filename' => $a['filename'] ?? basename($a['path']),
                'mime' => $a['mime'] ?? 'application/octet-stream',
                'size' => (int) ($a['size'] ?? 0),
            ])
            ->values()
            ->all();
    }

    public function delayInHours(): int
    {
        return ($this->delay_days * 24) + $this->delay_hours;
    }
}
