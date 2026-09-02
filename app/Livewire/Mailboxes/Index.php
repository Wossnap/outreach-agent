<?php

namespace App\Livewire\Mailboxes;

use App\Models\Mailbox;
use App\Models\Message;
use App\Services\Gmail\MailboxDisconnector;
use App\Services\Health\AutoPauseRules;
use App\Services\Sending\ApprovedMessageRecovery;
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

    public function resume(int $id, ApprovedMessageRecovery $recovery): void
    {
        Mailbox::query()->findOrFail($id)->update([
            'status' => Mailbox::STATUS_ACTIVE,
            'paused_reason' => null,
        ]);

        // Anything approved while every mailbox was paused is waiting for one.
        // This is the moment it became sendable, so do not make it wait for the
        // next scheduled sweep.
        $recovered = count($recovery->run());

        if ($recovered > 0) {
            session()->flash('status', $recovered.' email(s) that were waiting for a mailbox have been scheduled.');
        }
    }

    /**
     * Disconnect a mailbox: Google forgets us, the stored credentials go, and
     * anything queued on it is freed for another mailbox.
     *
     * Stronger than pausing, which keeps the connection. Getting this one back
     * needs a person to sign in at Google again.
     */
    public function disconnect(int $id, MailboxDisconnector $disconnector): void
    {
        $mailbox = Mailbox::query()->findOrFail($id);

        if ($mailbox->status === Mailbox::STATUS_DISCONNECTED) {
            return;
        }

        $result = $disconnector->disconnect($mailbox, 'Disconnected manually');

        $released = $result['released'] > 0
            ? " {$result['released']} queued email(s) were released for another mailbox."
            : '';

        session()->flash('status', "{$mailbox->email} disconnected.{$released} Use \"Connect Google mailbox\" to reconnect it.");
    }

    public function render()
    {
        $mailboxes = Mailbox::query()->with('domain')->orderBy('email')->get();

        // The stored pause reason is a record of why it stopped, written once
        // and never revisited. It kept saying "missing SPF or DKIM" for days
        // after the records were added. These re-run the same rules now, so
        // the page can say whether the cause still stands.
        $rules = app(AutoPauseRules::class);

        $blockers = $mailboxes
            ->filter(fn (Mailbox $m) => $m->status === Mailbox::STATUS_PAUSED)
            ->mapWithKeys(fn (Mailbox $m) => [$m->id => [
                'reason' => $rules->currentReason($m),
                'summary' => $rules->domainSummary($m),
            ]])
            ->all();

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
            'blockers' => $blockers,
        ]);
    }
}
