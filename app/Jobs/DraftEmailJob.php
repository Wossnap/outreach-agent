<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\Suppression;
use App\Services\Drafting\AnthropicDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DraftEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public int $enrollmentId,
        public int $stepPosition,
    ) {}

    public function handle(AnthropicDrafter $drafter): void
    {
        $enrollment = Enrollment::query()->with(['contact', 'automation', 'mailbox'])->find($this->enrollmentId);

        if (! $enrollment || ! $enrollment->isActive()) {
            return;
        }

        if (Suppression::isSuppressed($enrollment->contact->email)) {
            return;
        }

        $step = $enrollment->automation->activeSteps()->where('position', $this->stepPosition)->first();

        if (! $step) {
            return;
        }

        $message = Message::query()->firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'sequence_step_id' => $step->id],
            ['contact_id' => $enrollment->contact_id, 'mailbox_id' => $enrollment->mailbox_id, 'status' => Message::STATUS_DRAFTING],
        );

        // Idempotency: only draft into a fresh or previously-failed row.
        if (! $message->wasRecentlyCreated
            && ! in_array($message->status, [Message::STATUS_DRAFTING, Message::STATUS_DRAFT_FAILED], true)) {
            return;
        }

        $message->update(['status' => Message::STATUS_DRAFTING, 'mailbox_id' => $enrollment->mailbox_id]);

        $draft = $drafter->draft($enrollment, $step);

        $message->update([
            'subject' => $draft['subject'],
            'body_text' => $draft['body'],
            'ai_subject' => $draft['subject'],
            'ai_body' => $draft['body'],
            'status' => Message::STATUS_PENDING_APPROVAL,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $message = Message::query()
            ->where('enrollment_id', $this->enrollmentId)
            ->whereHas('sequenceStep', fn ($q) => $q->where('position', $this->stepPosition))
            ->first();

        $message?->update(['status' => Message::STATUS_DRAFT_FAILED, 'error' => $exception->getMessage()]);

        ActivityLog::record(
            event: 'draft_failed',
            message: "Drafting failed for enrollment #{$this->enrollmentId} step {$this->stepPosition}: {$exception->getMessage()}",
            level: ActivityLog::LEVEL_ERROR,
            subject: $message,
            context: ['enrollment_id' => $this->enrollmentId, 'step_position' => $this->stepPosition],
            retryable: true,
        );
    }
}
