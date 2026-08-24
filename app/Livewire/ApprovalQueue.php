<?php

namespace App\Livewire;

use App\Models\Message;
use App\Services\Sending\SendScheduler;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ApprovalQueue extends Component
{
    use WithPagination;

    /** @var array<int, array{subject: string, body: string}> Inline edits keyed by message id. */
    public array $drafts = [];

    /** @var array<int, bool> */
    public array $selected = [];

    public ?int $rejectingId = null;

    public string $rejectionNote = '';

    public function updatedDrafts(mixed $value, string $key): void
    {
        [$id, $field] = explode('.', $key);
        $message = Message::query()->find((int) $id);

        if (! $message || $message->status !== Message::STATUS_PENDING_APPROVAL) {
            return;
        }

        if (! in_array($field, ['subject', 'body'], true)) {
            return;
        }

        $message->update([
            $field === 'body' ? 'body_text' : 'subject' => $value,
            'edited_by_user' => $value !== ($field === 'body' ? $message->ai_body : $message->ai_subject)
                || $message->edited_by_user,
        ]);
    }

    public function approve(int $id, SendScheduler $scheduler): void
    {
        $message = Message::query()->with('enrollment')->findOrFail($id);

        if ($message->status !== Message::STATUS_PENDING_APPROVAL) {
            return;
        }

        $message->update(['status' => Message::STATUS_APPROVED, 'approved_at' => now()]);

        $slot = $scheduler->schedule($message);

        unset($this->drafts[$id], $this->selected[$id]);

        if ($slot === null) {
            session()->flash('queue-warning', 'Approved, but no sendable mailbox is connected — the email will stay unscheduled until a mailbox is available.');

            return;
        }

        session()->flash(
            'queue-status',
            'Approved — sending '.$slot->setTimezone(config('outreach.timezone'))->format('D j M, H:i:s').' ('.config('outreach.timezone').').'
        );
    }

    public function bulkApprove(SendScheduler $scheduler): void
    {
        $ids = array_keys(array_filter($this->selected));

        $count = 0;
        $unscheduled = 0;

        foreach ($ids as $id) {
            $message = Message::query()->with('enrollment')->find($id);

            if (! $message || $message->status !== Message::STATUS_PENDING_APPROVAL) {
                continue;
            }

            $message->update(['status' => Message::STATUS_APPROVED, 'approved_at' => now()]);

            if ($scheduler->schedule($message) === null) {
                $unscheduled++;
            }

            unset($this->drafts[$id], $this->selected[$id]);
            $count++;
        }

        $note = $unscheduled > 0 ? " ({$unscheduled} unscheduled — no sendable mailbox)" : '';
        session()->flash('queue-status', "Approved {$count} emails{$note}.");
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectionNote = '';
    }

    public function confirmReject(): void
    {
        $message = Message::query()->findOrFail($this->rejectingId);

        if ($message->status === Message::STATUS_PENDING_APPROVAL) {
            $message->update([
                'status' => Message::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_note' => $this->rejectionNote ?: null,
            ]);
        }

        unset($this->drafts[$message->id], $this->selected[$message->id]);
        $this->rejectingId = null;
        $this->rejectionNote = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectionNote = '';
    }

    public function render()
    {
        $messages = Message::query()
            ->where('status', Message::STATUS_PENDING_APPROVAL)
            ->with(['contact', 'mailbox', 'sequenceStep', 'enrollment.automation'])
            // created_at is second-precision, so drafts made in the same second
            // tie; without the id tiebreaker an edited row is returned last and
            // appears to jump to the bottom of the queue.
            ->oldest()
            ->orderBy('id')
            ->paginate(15);

        foreach ($messages as $message) {
            $this->drafts[$message->id] ??= [
                'subject' => (string) $message->subject,
                'body' => (string) $message->body_text,
            ];
        }

        return view('livewire.approval-queue', [
            'messages' => $messages,
            'priorThreads' => $this->priorThreads($messages->getCollection()),
        ]);
    }

    /**
     * Sent messages per enrollment, for the follow-up context accordion.
     *
     * @return array<int, Collection<int, Message>>
     */
    protected function priorThreads(Collection $messages): array
    {
        $followUps = $messages->filter(fn (Message $m) => ($m->sequenceStep?->position ?? 1) > 1);

        if ($followUps->isEmpty()) {
            return [];
        }

        return Message::query()
            ->whereIn('enrollment_id', $followUps->pluck('enrollment_id'))
            ->where('status', Message::STATUS_SENT)
            ->orderBy('sent_at')
            ->get()
            ->groupBy('enrollment_id')
            ->all();
    }
}
