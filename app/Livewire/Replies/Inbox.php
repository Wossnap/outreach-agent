<?php

namespace App\Livewire\Replies;

use App\Models\Reply;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Inbox extends Component
{
    use WithPagination;

    #[Url]
    public string $classification = '';

    #[Url]
    public string $unread = '';

    public function markRead(int $id): void
    {
        Reply::query()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        Reply::query()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function updatedClassification(): void
    {
        $this->resetPage();
    }

    public function updatedUnread(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $replies = Reply::query()
            ->with(['contact', 'mailbox', 'enrollment.automation'])
            ->when($this->classification !== '', fn ($q) => $q->where('classification', $this->classification))
            ->when($this->unread === '1', fn ($q) => $q->whereNull('read_at'))
            ->orderByRaw('read_at IS NULL desc')
            ->latest('received_at')
            ->paginate(20);

        return view('livewire.replies.inbox', [
            'replies' => $replies,
            'unreadCount' => Reply::query()->whereNull('read_at')->count(),
        ]);
    }
}
