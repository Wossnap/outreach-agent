<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Gmail\GmailClientFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Proactively refreshes access tokens so dead refresh tokens surface here
 * (as a disconnected mailbox + activity log entry) instead of at send time.
 */
class RefreshMailboxTokens extends Command
{
    protected $signature = 'mailboxes:refresh-tokens';

    protected $description = 'Refresh Google access tokens for all connected mailboxes';

    public function handle(GmailClientFactory $factory): int
    {
        $mailboxes = Mailbox::query()
            ->whereIn('status', [Mailbox::STATUS_ACTIVE, Mailbox::STATUS_PAUSED])
            ->whereNotNull('google_refresh_token')
            ->get();

        $failed = 0;

        foreach ($mailboxes as $mailbox) {
            try {
                $factory->authorizedClientFor($mailbox);
            } catch (Throwable $e) {
                // authorizedClientFor already logged + disconnected on invalid_grant.
                $this->warn("{$mailbox->email}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info('Refreshed tokens for '.($mailboxes->count() - $failed).'/'.$mailboxes->count().' mailboxes.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
