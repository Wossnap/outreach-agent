<?php

namespace App\Livewire\Health;

use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\Mailbox;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function runChecks(): void
    {
        Artisan::call('health:check-dns');
        Artisan::call('health:check-dns', ['--dnsbl' => true]);
        Artisan::call('health:evaluate');

        session()->flash('health-status', 'Checks completed.');
    }

    public function render()
    {
        return view('livewire.health.dashboard', [
            'domains' => Domain::query()->withCount('mailboxes')->orderBy('name')->get(),
            'mailboxes' => Mailbox::query()->with('domain')->orderBy('email')->get(),
            'pauseEvents' => ActivityLog::query()
                ->whereIn('event', ['mailbox_paused', 'token_refresh_failed'])
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }
}
