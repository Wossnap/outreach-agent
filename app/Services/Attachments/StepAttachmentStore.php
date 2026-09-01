<?php

namespace App\Services\Attachments;

use App\Models\SequenceStep;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores the files attached to a sequence step.
 *
 * The dashboard and the API both go through here so a file uploaded either way
 * lands in the same place and is validated by the same rules.
 */
class StepAttachmentStore
{
    /**
     * Validation rules for an uploaded attachment, shared by both callers.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.config('outreach.attachments.max_size_kb'),
            'mimes:'.implode(',', config('outreach.attachments.allowed_extensions')),
        ];
    }

    /**
     * @return array{id: string, disk: string, path: string, filename: string, mime: string, size: int}
     */
    public function add(SequenceStep $step, UploadedFile $file): array
    {
        $existing = $step->attachmentList();

        if (count($existing) >= (int) config('outreach.attachments.max_per_step')) {
            throw new RuntimeException(
                'This step already has the maximum of '.config('outreach.attachments.max_per_step').' attachments.'
            );
        }

        $disk = (string) config('outreach.attachments.disk');

        // Stored under a generated name: two steps can hold files with the
        // same original name, and the original name is kept separately for the
        // recipient to see.
        $path = $file->store('step-attachments/'.$step->id, $disk);

        if ($path === false) {
            throw new RuntimeException('Could not store the attachment.');
        }

        $descriptor = [
            'id' => (string) Str::uuid(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
        ];

        $step->update(['attachments' => [...$existing, $descriptor]]);

        return $descriptor;
    }

    public function remove(SequenceStep $step, string $id): bool
    {
        $existing = $step->attachmentList();

        $target = collect($existing)->firstWhere('id', $id);

        if (! $target) {
            return false;
        }

        Storage::disk($target['disk'])->delete($target['path']);

        $step->update([
            'attachments' => collect($existing)->reject(fn (array $a) => $a['id'] === $id)->values()->all(),
        ]);

        return true;
    }
}
