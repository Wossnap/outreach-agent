<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Postmaster\PostmasterSync;
use Illuminate\Console\Command;

/**
 * Pulls each sending domain's Gmail Postmaster figures. Daily; a domain that
 * cannot be read is reported and the rest are still synced.
 */
class SyncPostmasterStats extends Command
{
    protected $signature = 'postmaster:sync {--domain= : Only this domain name} {--days= : How many days back to ask for}';

    protected $description = 'Pull Gmail Postmaster Tools spam-rate figures for every sending domain';

    public function handle(PostmasterSync $sync): int
    {
        if (! config('outreach.postmaster.enabled')) {
            $this->info('Postmaster sync is switched off (OUTREACH_POSTMASTER_SYNC).');

            return self::SUCCESS;
        }

        $domains = Domain::query()
            ->with('mailboxes')
            ->when($this->option('domain'), fn ($q, $name) => $q->where('name', $name))
            ->orderBy('name')
            ->get();

        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        foreach ($domains as $domain) {
            $result = $sync->sync($domain, $days);

            $this->line(($result['ok'] ? '<info>ok</info>  ' : '<comment>--</comment>  ').$result['domain'].': '.$result['text']);
        }

        $this->info('Synced '.$domains->count().' domain(s).');

        return self::SUCCESS;
    }
}
