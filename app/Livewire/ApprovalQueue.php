<?php

namespace App\Livewire;

use App\Models\Message;
use App\Services\Sending\MessageApprover;
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

    public function approve(int $id, MessageApprover $approver): void
    {
        $message = Message::query()->with('enrollment')->findOrFail($id);

        $result = $approver->approve($message);

        if (! $result['approved']) {
            return;
        }

        $slot = $result['scheduled_at'];

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

    public function bulkApprove(MessageApprover $approver): void
    {
        $ids = array_keys(array_filter($this->selected));

        $count = 0;
        $unscheduled = 0;

        foreach ($ids as $id) {
            $message = Message::query()->with('enrollment')->find($id);

            if (! $message) {
                continue;
            }

            $result = $approver->approve($message);

            if (! $result['approved']) {
                continue;
            }

            if ($result['scheduled_at'] === null) {
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

    public function confirmReject(MessageApprover $approver): void
    {
        $message = Message::query()->with('enrollment')->findOrFail($this->rejectingId);

        $approver->reject($message, $this->rejectionNote);

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
            // Approved but never given a send slot — happens when every mailbox
            // was paused or disconnected at the moment of approval. Surfaced so
            // these cannot sit unsent unnoticed; the reconciler retries them.
            'waiting' => Message::query()
                ->where('status', Message::STATUS_APPROVED)
                ->whereNull('sent_at')
                ->with(['contact', 'sequenceStep'])
                ->orderBy('approved_at')
                ->get(),
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
