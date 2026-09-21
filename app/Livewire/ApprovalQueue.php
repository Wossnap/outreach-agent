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

    /**
     * Compact shows each draft as the email it would be, to be read and
     * approved in one pass. Full shows every draft as a form, which is what
     * this page was, and is what is wanted when several need rewriting.
     */
    public const VIEW_COMPACT = 'compact';

    public const VIEW_FULL = 'full';

    /** @var array<int, array{subject: string, body: string}> Inline edits keyed by message id. */
    public array $drafts = [];

    /** @var array<int, bool> */
    public array $selected = [];

    public string $view = self::VIEW_COMPACT;

    /** @var array<int, bool> Drafts opened as a form while in the compact view. */
    public array $editing = [];

    public ?int $rejectingId = null;

    /** Whether the rejection note being written is for everything ticked. */
    public bool $rejectingSelected = false;

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

    public function toggleView(): void
    {
        $this->view = $this->view === self::VIEW_COMPACT ? self::VIEW_FULL : self::VIEW_COMPACT;
    }

    public function toggleEdit(int $id): void
    {
        if ($this->editing[$id] ?? false) {
            unset($this->editing[$id]);
        } else {
            $this->editing[$id] = true;
        }
    }

    /** Whether this draft is shown as a form rather than as the email it would be. */
    public function isEditing(int $id): bool
    {
        return $this->view === self::VIEW_FULL || ($this->editing[$id] ?? false);
    }

    /**
     * Tick or clear every draft on the page being looked at, and no others.
     *
     * The page rather than the whole queue on purpose: approving is sending,
     * and a tick box that quietly selects a hundred drafts behind the fifteen
     * on screen is how something goes out unread.
     *
     * @param  array<int, int|string>  $ids  the drafts currently drawn
     */
    public function toggleSelectPage(array $ids): void
    {
        $ids = array_map(intval(...), $ids);

        $everyOneAlreadyTicked = $ids !== [] && collect($ids)->every(fn (int $id) => $this->selected[$id] ?? false);

        foreach ($ids as $id) {
            if ($everyOneAlreadyTicked) {
                unset($this->selected[$id]);
            } else {
                $this->selected[$id] = true;
            }
        }
    }

    /** @return array<int> */
    public function selectedIds(): array
    {
        return array_map(intval(...), array_keys(array_filter($this->selected)));
    }

    public function approve(int $id, MessageApprover $approver): void
    {
        $message = Message::query()->with('enrollment')->findOrFail($id);

        $result = $approver->approve($message);

        if (! $result['approved']) {
            return;
        }

        $slot = $result['scheduled_at'];

        $this->forget($id);

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
        $count = 0;
        $unscheduled = 0;

        foreach ($this->selectedIds() as $id) {
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

            $this->forget($id);
            $count++;
        }

        $note = $unscheduled > 0 ? " ({$unscheduled} unscheduled — no sendable mailbox)" : '';
        session()->flash('queue-status', "Approved {$count} emails{$note}.");
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectingSelected = false;
        $this->rejectionNote = '';
    }

    public function startRejectSelected(): void
    {
        $this->rejectingId = null;
        $this->rejectingSelected = true;
        $this->rejectionNote = '';
    }

    /**
     * Reject the one draft asked about, or everything ticked.
     *
     * One note covers a batch: the reason for rejecting six drafts at once is
     * nearly always the same reason six times.
     */
    public function confirmReject(MessageApprover $approver): void
    {
        $ids = $this->rejectingSelected ? $this->selectedIds() : array_filter([$this->rejectingId]);

        $count = 0;

        foreach ($ids as $id) {
            $message = Message::query()->with('enrollment')->find($id);

            if (! $message || ! $approver->reject($message, $this->rejectionNote)) {
                continue;
            }

            $this->forget($id);
            $count++;
        }

        if ($this->rejectingSelected) {
            session()->flash('queue-status', "Rejected {$count} emails.");
        }

        $this->cancelReject();
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectingSelected = false;
        $this->rejectionNote = '';
    }

    /** Drop everything the page remembers about a draft that has left the queue. */
    protected function forget(int $id): void
    {
        unset($this->drafts[$id], $this->selected[$id], $this->editing[$id]);
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
            'selectedCount' => count($this->selectedIds()),
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
