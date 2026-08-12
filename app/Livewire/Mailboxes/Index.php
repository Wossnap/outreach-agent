<?php

namespace App\Livewire\Mailboxes;

use App\Models\Mailbox;
use App\Models\Message;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public ?int $editingId = null;

    /** @var array{daily_cap: int, min_gap_minutes: int, max_gap_minutes: int, send_window_start: string, send_window_end: string, send_timezone: string, send_weekends: bool, warmup_enabled: bool, display_name: string} */
    public array $form = [];

    public function edit(int $id): void
    {
        $mailbox = Mailbox::query()->findOrFail($id);

        $this->editingId = $id;
        $this->form = [
            'display_name' => (string) $mailbox->display_name,
            'daily_cap' => $mailbox->daily_cap,
            'min_gap_minutes' => $mailbox->min_gap_minutes,
            'max_gap_minutes' => $mailbox->max_gap_minutes,
            'send_window_start' => $mailbox->send_window_start,
            'send_window_end' => $mailbox->send_window_end,
            'send_timezone' => $mailbox->send_timezone,
            'send_weekends' => $mailbox->send_weekends,
            'warmup_enabled' => $mailbox->warmup_enabled,
        ];
    }

    public function save(): void
    {
        $this->validate([
            'form.display_name' => ['nullable', 'string', 'max:255'],
            'form.daily_cap' => ['required', 'integer', 'min:1', 'max:500'],
            'form.min_gap_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'form.max_gap_minutes' => ['required', 'integer', 'gte:form.min_gap_minutes', 'max:240'],
            'form.send_window_start' => ['required', 'date_format:H:i'],
            'form.send_window_end' => ['required', 'date_format:H:i', 'after:form.send_window_start'],
            'form.send_timezone' => ['required', 'timezone'],
        ]);

        Mailbox::query()->findOrFail($this->editingId)->update($this->form);

        $this->editingId = null;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function pause(int $id): void
    {
        Mailbox::query()->findOrFail($id)->pause('Paused manually');
    }

    public function resume(int $id): void
    {
        Mailbox::query()->findOrFail($id)->update([
            'status' => Mailbox::STATUS_ACTIVE,
            'paused_reason' => null,
        ]);
    }

    public function render()
    {
        $mailboxes = Mailbox::query()->with('domain')->orderBy('email')->get();

        $sentToday = Message::query()
            ->whereIn('mailbox_id', $mailboxes->pluck('id'))
            ->where('status', Message::STATUS_SENT)
            ->whereBetween('sent_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('mailbox_id, count(*) as total')
            ->groupBy('mailbox_id')
            ->pluck('total', 'mailbox_id');

        return view('livewire.mailboxes.index', [
            'mailboxes' => $mailboxes,
            'sentToday' => $sentToday,
        ]);
    }
}
