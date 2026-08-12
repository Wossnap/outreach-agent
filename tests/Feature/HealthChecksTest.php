<?php

namespace Tests\Feature;

use App\Console\Commands\EvaluateMailboxHealth;
use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Services\Health\DnsblChecker;
use App\Services\Health\DnsHealthChecker;
use App\Services\Health\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeDnsResolver implements DnsResolver
{
    public function __construct(
        /** @var array<string, array<string>> */
        public array $txt = [],
        /** @var array<string, array<string>> */
        public array $a = [],
    ) {}

    public function txtRecords(string $host): array
    {
        return $this->txt[$host] ?? [];
    }

    public function aRecords(string $host): array
    {
        return $this->a[$host] ?? [];
    }
}

class HealthChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function bindDns(array $txt = [], array $a = []): void
    {
        $this->app->instance(DnsResolver::class, new FakeDnsResolver($txt, $a));
    }

    public function test_healthy_domain_passes_all_dns_checks(): void
    {
        $domain = Domain::factory()->create(['name' => 'good.test']);
        $this->bindDns(txt: [
            'good.test' => ['v=spf1 include:_spf.google.com ~all'],
            'google._domainkey.good.test' => ['v=DKIM1; k=rsa; p=MIIB...'],
            '_dmarc.good.test' => ['v=DMARC1; p=quarantine; rua=mailto:d@good.test'],
        ]);

        app(DnsHealthChecker::class)->check($domain);

        $fresh = $domain->fresh();
        $this->assertSame('ok', $fresh->spf_status);
        $this->assertSame('ok', $fresh->dkim_status);
        $this->assertSame('ok', $fresh->dmarc_status);
        $this->assertSame(Domain::HEALTH_HEALTHY, $fresh->health_status);
    }

    public function test_missing_spf_is_critical(): void
    {
        $domain = Domain::factory()->create(['name' => 'bare.test']);
        $this->bindDns();

        app(DnsHealthChecker::class)->check($domain);

        $fresh = $domain->fresh();
        $this->assertSame('missing', $fresh->spf_status);
        $this->assertSame(Domain::HEALTH_CRITICAL, $fresh->health_status);
    }

    public function test_dmarc_p_none_warns(): void
    {
        $domain = Domain::factory()->create(['name' => 'soft.test']);
        $this->bindDns(txt: [
            'soft.test' => ['v=spf1 include:_spf.google.com ~all'],
            'google._domainkey.soft.test' => ['v=DKIM1; k=rsa; p=MIIB...'],
            '_dmarc.soft.test' => ['v=DMARC1; p=none'],
        ]);

        app(DnsHealthChecker::class)->check($domain);

        $fresh = $domain->fresh();
        $this->assertSame('warn', $fresh->dmarc_status);
        $this->assertSame(Domain::HEALTH_WARNING, $fresh->health_status);
    }

    public function test_dnsbl_listing_flags_domain(): void
    {
        $domain = Domain::factory()->create(['name' => 'listed.test']);
        $this->bindDns(a: ['listed.test.dbl.spamhaus.org' => ['127.0.1.2']]);

        app(DnsblChecker::class)->check($domain);

        $fresh = $domain->fresh();
        $this->assertTrue($fresh->dnsbl_listed);
        $this->assertSame(['dbl.spamhaus.org'], $fresh->dnsbl_zones);
        $this->assertSame(Domain::HEALTH_CRITICAL, $fresh->health_status);
    }

    public function test_spamhaus_error_codes_are_not_listings(): void
    {
        $domain = Domain::factory()->create(['name' => 'fine.test']);
        $this->bindDns(a: ['fine.test.dbl.spamhaus.org' => ['127.255.255.254']]);

        app(DnsblChecker::class)->check($domain);

        $this->assertFalse($domain->fresh()->dnsbl_listed);
    }

    public function test_evaluate_computes_rates_and_auto_pauses_on_bounce_rate(): void
    {
        $domain = Domain::factory()->create(['spf_status' => 'ok', 'dkim_status' => 'ok']);
        $mailbox = Mailbox::factory()->connected()->create(['domain_id' => $domain->id]);

        Message::factory()->count(25)->sent()->create(['mailbox_id' => $mailbox->id, 'sent_at' => now()->subDay()]);
        Reply::factory()->count(3)->create([
            'mailbox_id' => $mailbox->id,
            'classification' => Reply::CLASS_BOUNCE,
            'received_at' => now()->subHours(5),
        ]);

        $this->artisan(EvaluateMailboxHealth::class)->assertSuccessful();

        $fresh = $mailbox->fresh();
        $this->assertSame(25, $fresh->sent_7d);
        $this->assertSame(0.12, $fresh->bounce_rate_7d);
        $this->assertSame(Mailbox::STATUS_PAUSED, $fresh->status);
        $this->assertStringContainsString('bounce rate', $fresh->paused_reason);
        $this->assertTrue(ActivityLog::query()->where('event', 'mailbox_paused')->exists());
    }

    public function test_evaluate_does_not_pause_low_volume_mailboxes(): void
    {
        $domain = Domain::factory()->create(['spf_status' => 'ok', 'dkim_status' => 'ok']);
        $mailbox = Mailbox::factory()->connected()->create(['domain_id' => $domain->id]);

        // 5 sends, 1 bounce = 20% but under the 20-send minimum.
        Message::factory()->count(5)->sent()->create(['mailbox_id' => $mailbox->id, 'sent_at' => now()->subDay()]);
        Reply::factory()->create([
            'mailbox_id' => $mailbox->id,
            'classification' => Reply::CLASS_BOUNCE,
            'received_at' => now()->subHours(5),
        ]);

        $this->artisan(EvaluateMailboxHealth::class)->assertSuccessful();

        $this->assertSame(Mailbox::STATUS_ACTIVE, $mailbox->fresh()->status);
    }

    public function test_evaluate_pauses_when_domain_blocklisted(): void
    {
        $domain = Domain::factory()->create([
            'spf_status' => 'ok',
            'dkim_status' => 'ok',
            'dnsbl_listed' => true,
            'dnsbl_zones' => ['dbl.spamhaus.org'],
        ]);
        $mailbox = Mailbox::factory()->connected()->create(['domain_id' => $domain->id]);

        $this->artisan(EvaluateMailboxHealth::class)->assertSuccessful();

        $fresh = $mailbox->fresh();
        $this->assertSame(Mailbox::STATUS_PAUSED, $fresh->status);
        $this->assertSame('critical', $fresh->health_status);
    }
}
