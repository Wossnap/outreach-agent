<?php

namespace Tests\Feature;

use App\Livewire\Health\Dashboard;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Health\AutoPauseRules;
use App\Services\Health\DnsHealthChecker;
use App\Support\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What counts as a fault on the Health page.
 *
 * Only things that stop mail authenticating. A page that goes amber for a
 * correctly configured domain teaches people to ignore amber, which costs more
 * than it saves on the day it matters.
 */
class DomainHealthScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function bindDns(array $txt): void
    {
        $this->app->instance(DnsResolver::class, new FakeDnsResolver($txt));
    }

    protected function checkWith(string $name, ?string $spf, ?string $dkim, ?string $dmarc): Domain
    {
        $domain = Domain::factory()->create(['name' => $name]);

        $this->bindDns(array_filter([
            $name => $spf ? [$spf] : null,
            "google._domainkey.{$name}" => $dkim ? [$dkim] : null,
            "_dmarc.{$name}" => $dmarc ? [$dmarc] : null,
        ]));

        app(DnsHealthChecker::class)->check($domain);

        return $domain->fresh();
    }

    // ---------------------------------------------------------------
    // The rules that must not be relaxed by any of this
    // ---------------------------------------------------------------

    public function test_a_domain_with_no_spf_is_still_critical(): void
    {
        $domain = $this->checkWith('nospf.test', null, 'v=DKIM1; k=rsa; p=AAA', 'v=DMARC1; p=none');

        $this->assertSame('missing', $domain->spf_status);
        $this->assertSame(Domain::HEALTH_CRITICAL, $domain->health_status);
    }

    public function test_a_domain_with_no_dkim_is_still_critical(): void
    {
        $domain = $this->checkWith('nodkim.test', 'v=spf1 include:_spf.google.com ~all', null, 'v=DMARC1; p=none');

        $this->assertSame('missing', $domain->dkim_status);
        $this->assertSame(Domain::HEALTH_CRITICAL, $domain->health_status);
    }

    public function test_spf_that_omits_google_is_still_a_warning(): void
    {
        // Real fault: mail sent through Gmail fails the check.
        $domain = $this->checkWith('badspf.test', 'v=spf1 include:spf.other.net ~all', 'v=DKIM1; k=rsa; p=AAA', 'v=DMARC1; p=none');

        $this->assertSame('warn', $domain->spf_status);
        $this->assertSame(Domain::HEALTH_WARNING, $domain->health_status);
    }

    // ---------------------------------------------------------------
    // DMARC never makes a domain unhealthy
    // ---------------------------------------------------------------

    public function test_dmarc_p_none_is_healthy_and_labelled_as_monitoring(): void
    {
        // The setting both the standard and Google tell you to start with.
        // Flagging it would be flagging correct work.
        $domain = $this->checkWith('monitor.test', 'v=spf1 include:_spf.google.com ~all', 'v=DKIM1; k=rsa; p=AAA', 'v=DMARC1; p=none; rua=mailto:d@monitor.test');

        $this->assertSame('monitoring', $domain->dmarc_status);
        $this->assertSame(Domain::HEALTH_HEALTHY, $domain->health_status);
        $this->assertSame('ok (monitoring)', $domain->statusLabel('dmarc_status'));
    }

    public function test_dmarc_enforcing_is_healthy(): void
    {
        $domain = $this->checkWith('enforce.test', 'v=spf1 include:_spf.google.com ~all', 'v=DKIM1; k=rsa; p=AAA', 'v=DMARC1; p=reject');

        $this->assertSame('enforcing', $domain->dmarc_status);
        $this->assertSame(Domain::HEALTH_HEALTHY, $domain->health_status);
        $this->assertSame('ok (enforcing)', $domain->statusLabel('dmarc_status'));
    }

    public function test_no_dmarc_at_all_is_healthy_and_reads_as_a_note(): void
    {
        // Gmail asks for DMARC only above 5,000 messages a day, and accepts
        // p=none even then. It does not gate delivery.
        $domain = $this->checkWith('nodmarc.test', 'v=spf1 include:_spf.google.com ~all', 'v=DKIM1; k=rsa; p=AAA', null);

        $this->assertSame('absent', $domain->dmarc_status);
        $this->assertSame(Domain::HEALTH_HEALTHY, $domain->health_status);
        $this->assertSame('none set', $domain->statusLabel('dmarc_status'));
        $this->assertFalse($domain->statusIsFault('dmarc_status'));
    }

    // ---------------------------------------------------------------
    // A domain nothing sends from is not scored
    // ---------------------------------------------------------------

    public function test_a_domain_whose_only_mailbox_is_disconnected_counts_as_unused(): void
    {
        $domain = Domain::factory()->create(['name' => 'retired.test']);
        Mailbox::factory()->connected()->create([
            'domain_id' => $domain->id,
            'status' => Mailbox::STATUS_DISCONNECTED,
        ]);

        $this->assertSame(0, $domain->fresh()->load('mailboxes')->sendingMailboxCount());
    }

    public function test_a_domain_with_a_live_mailbox_is_still_counted(): void
    {
        $domain = Domain::factory()->create(['name' => 'live.test']);
        Mailbox::factory()->connected()->create([
            'domain_id' => $domain->id,
            'status' => Mailbox::STATUS_PAUSED,
        ]);

        // Paused is not disconnected: it can send again with one click, so its
        // DNS still matters.
        $this->assertSame(1, $domain->fresh()->load('mailboxes')->sendingMailboxCount());
    }

    public function test_an_unused_domain_is_not_shown_as_a_problem_even_with_bad_records(): void
    {
        // gmail.com locally: no DKIM key is possible because we do not own its
        // DNS. True, and irrelevant, because nothing sends from it. Showing a
        // red badge beside a "not in use" label tells two different stories.
        $domain = Domain::factory()->create([
            'name' => 'unused.test',
            'spf_status' => 'warn',
            'dkim_status' => 'missing',
            'dmarc_status' => 'monitoring',
        ]);

        Mailbox::factory()->connected()->create([
            'domain_id' => $domain->id,
            'status' => Mailbox::STATUS_DISCONNECTED,
        ]);

        $this->actingAs(User::factory()->create());

        $html = Livewire::test(Dashboard::class)->html();

        $this->assertStringContainsString('not in use', $html);

        // The words stay so the information is not lost, but nothing about
        // this row is coloured as a fault.
        $row = Str::between($html, 'unused.test', '</tr>');
        $this->assertStringNotContainsString('data-tone="danger"', $row);
        $this->assertStringNotContainsString('data-tone="warn"', $row);
    }

    public function test_a_domain_in_use_with_bad_records_is_still_coloured_red(): void
    {
        $domain = Domain::factory()->create([
            'name' => 'inuse.test',
            'spf_status' => 'ok',
            'dkim_status' => 'missing',
            'dmarc_status' => 'monitoring',
        ]);

        Mailbox::factory()->connected()->create([
            'domain_id' => $domain->id,
            'status' => Mailbox::STATUS_ACTIVE,
        ]);

        $this->actingAs(User::factory()->create());

        $html = Livewire::test(Dashboard::class)->html();
        $row = Str::between($html, 'inuse.test', '</tr>');

        $this->assertStringContainsString('data-tone="danger"', $row);
        $this->assertStringNotContainsString('not in use', $row);
    }

    // ---------------------------------------------------------------
    // Whether a pause still applies, re-checked live
    // ---------------------------------------------------------------

    public function test_a_pause_reason_that_no_longer_applies_reports_as_clear(): void
    {
        $domain = Domain::factory()->create([
            'name' => 'fixed.test',
            'spf_status' => 'ok',
            'dkim_status' => 'ok',
            'dmarc_status' => 'monitoring',
            'dnsbl_listed' => false,
        ]);

        $mailbox = Mailbox::factory()->connected()->create([
            'domain_id' => $domain->id,
            'status' => Mailbox::STATUS_PAUSED,
            'paused_reason' => 'Auto-paused: domain fixed.test is missing SPF or DKIM.',
        ]);

        // The stored sentence is stale. What matters is whether the cause is
        // still true, which it is not.
        $this->assertNull(app(AutoPauseRules::class)->currentReason($mailbox));
    }

    public function test_a_pause_reason_that_does_still_apply_says_what_is_missing(): void
    {
        $domain = Domain::factory()->create([
            'name' => 'broken.test',
            'spf_status' => 'ok',
            'dkim_status' => 'missing',
            'dnsbl_listed' => false,
        ]);

        $mailbox = Mailbox::factory()->connected()->create([
            'domain_id' => $domain->id,
            'status' => Mailbox::STATUS_PAUSED,
        ]);

        $reason = app(AutoPauseRules::class)->currentReason($mailbox);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('DKIM', $reason);
        $this->assertStringNotContainsString('SPF', $reason);
    }

    public function test_dmarc_never_blocks_a_mailbox(): void
    {
        $domain = Domain::factory()->create([
            'name' => 'nodmarc2.test',
            'spf_status' => 'ok',
            'dkim_status' => 'ok',
            'dmarc_status' => 'absent',
            'dnsbl_listed' => false,
        ]);

        $mailbox = Mailbox::factory()->connected()->create(['domain_id' => $domain->id]);

        $this->assertNull(app(AutoPauseRules::class)->currentReason($mailbox));
    }
}
