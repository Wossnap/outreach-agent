<?php

namespace App\Livewire\Activity;

use App\Jobs\DraftEmailJob;
use App\Models\ActivityLog;
use App\Models\Message;
use App\Services\Sending\SendScheduler;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $level = '';

    public function updatedLevel(): void
    {
        $this->resetPage();
    }

    public function retry(int $logId, SendScheduler $scheduler): void
    {
        $log = ActivityLog::query()->findOrFail($logId);

        if (! $log->retryable || $log->retried_at) {
            return;
        }

        $message = $log->subject instanceof Message ? $log->subject : null;

        $outcome = match (true) {
            in_array($log->event, ['draft_failed', 'draft_stuck'], true) => $this->retryDraft($log, $message),
            in_array($log->event, ['send_failed', 'send_stuck'], true) => $this->retrySend($message, $scheduler),
            default => false,
        };

        if ($outcome) {
            $log->update(['retried_at' => now()]);
            session()->flash('activity-status', 'Retry queued.');
        } else {
            session()->flash('activity-error', 'Could not retry — the related message or enrollment no longer allows it.');
        }
    }

    protected function retryDraft(ActivityLog $log, ?Message $message): bool
    {
        $enrollmentId = $message?->enrollment_id ?? $log->context['enrollment_id'] ?? null;
        $position = $message?->sequenceStep?->position ?? $log->context['step_position'] ?? null;

        if (! $enrollmentId || ! $position) {
            return false;
        }

        if ($message && $message->status === Message::STATUS_DRAFT_FAILED) {
            $message->update(['status' => Message::STATUS_DRAFTING, 'error' => null]);
        }

        DraftEmailJob::dispatch($enrollmentId, (int) $position);

        return true;
    }

    protected function retrySend(?Message $message, SendScheduler $scheduler): bool
    {
        if (! $message || $message->status !== Message::STATUS_FAILED || ! $message->enrollment->isActive()) {
            return false;
        }

        $message->update(['status' => Message::STATUS_APPROVED, 'error' => null]);

        return $scheduler->schedule($message) !== null;
    }

    public function render()
    {
        return view('livewire.activity.index', [
            'logs' => ActivityLog::query()
                ->when($this->level !== '', fn ($q) => $q->where('level', $this->level))
                ->latest()
                ->paginate(30),
        ]);
    }
}
