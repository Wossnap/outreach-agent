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

        // "Checks completed" alone gave no way to tell a clean result from a
        // check that did not really run. Say what was found, including when
        // everything is fine.
        session()->flash('health-results', $this->summarise());
    }

    /**
     * One plain sentence per domain describing what the check found.
     *
     * @return array<int, array{domain: string, ok: bool, text: string}>
     */
    protected function summarise(): array
    {
        return Domain::query()->with('mailboxes')->orderBy('name')->get()
            ->map(function (Domain $domain) {
                if ($domain->sendingMailboxCount() === 0) {
                    return [
                        'domain' => $domain->name,
                        'ok' => true,
                        'text' => 'Not in use, nothing sends from this domain.',
                    ];
                }

                $faults = [];

                if ($domain->dnsbl_listed) {
                    $faults[] = 'listed on '.implode(', ', $domain->dnsbl_zones ?? []);
                }

                foreach (['spf_status' => 'SPF', 'dkim_status' => 'DKIM'] as $field => $label) {
                    if ($domain->{$field} === 'missing') {
                        $faults[] = "no {$label} record";
                    } elseif ($domain->{$field} === 'warn') {
                        $faults[] = strtolower($label).' record needs attention';
                    }
                }

                if ($faults !== []) {
                    return [
                        'domain' => $domain->name,
                        'ok' => false,
                        'text' => ucfirst(implode(', ', $faults)).'. Mail from this domain will not authenticate until it is fixed.',
                    ];
                }

                $dmarc = match ($domain->dmarc_status) {
                    'monitoring' => 'DMARC monitoring',
                    'enforcing' => 'DMARC enforcing',
                    'absent' => 'no DMARC, which is optional at this volume',
                    default => 'DMARC '.$domain->dmarc_status,
                };

                return [
                    'domain' => $domain->name,
                    'ok' => true,
                    'text' => "All good. SPF and DKIM present, {$dmarc}, not blocklisted.",
                ];
            })
            ->all();
    }

    public function render()
    {
        return view('livewire.health.dashboard', [
            'domains' => Domain::query()->with('mailboxes')->withCount('mailboxes')->orderBy('name')->get(),
            'mailboxes' => Mailbox::query()->with('domain')->orderBy('email')->get(),
            'pauseEvents' => ActivityLog::query()
                ->whereIn('event', ['mailbox_paused', 'token_refresh_failed'])
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }
}
