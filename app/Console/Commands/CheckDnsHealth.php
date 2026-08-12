<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Health\DnsblChecker;
use App\Services\Health\DnsHealthChecker;
use Illuminate\Console\Command;
use Throwable;

class CheckDnsHealth extends Command
{
    protected $signature = 'health:check-dns {--dnsbl : Also run blocklist checks}';

    protected $description = 'Check SPF/DKIM/DMARC (and optionally DNSBL listings) for every sending domain';

    public function handle(DnsHealthChecker $dns, DnsblChecker $dnsbl): int
    {
        $failed = 0;

        foreach (Domain::query()->get() as $domain) {
            try {
                if ($this->option('dnsbl')) {
                    $dnsbl->check($domain);
                } else {
                    $dns->check($domain);
                }

                $fresh = $domain->fresh();
                $this->line("{$domain->name}: spf={$fresh->spf_status} dkim={$fresh->dkim_status} dmarc={$fresh->dmarc_status} dnsbl=".($fresh->dnsbl_listed ? 'LISTED' : 'clear'));
            } catch (Throwable $e) {
                $this->warn("{$domain->name}: {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
