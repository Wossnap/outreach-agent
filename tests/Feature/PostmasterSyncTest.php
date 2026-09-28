<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPostmasterStats;
use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\PostmasterStat;
use App\Services\Gmail\GmailClientFactory;
use App\Services\Postmaster\PostmasterSync;
use Google\Service\Exception as GoogleException;
use Google\Service\PostmasterTools;
use Google\Service\PostmasterTools\ComplianceStatus;
use Google\Service\PostmasterTools\Date;
use Google\Service\PostmasterTools\Domain as PostmasterDomain;
use Google\Service\PostmasterTools\DomainStat;
use Google\Service\PostmasterTools\QueryDomainStatsResponse;
use Google\Service\PostmasterTools\Resource\Domains;
use Google\Service\PostmasterTools\Resource\DomainsDomainStats;
use Google\Service\PostmasterTools\StatisticValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PostmasterSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, array<string, float>>  $days  date => [metric => value] */
    protected function fakePostmaster(array $days, ?GoogleException $failure = null): void
    {
        $stats = [];

        foreach ($days as $date => $metrics) {
            [$y, $m, $d] = array_map('intval', explode('-', $date));

            foreach ($metrics as $metric => $number) {
                $when = new Date;
                $when->setYear($y);
                $when->setMonth($m);
                $when->setDay($d);
                $value = new StatisticValue;
                $value->setDoubleValue($number);
                $stat = new DomainStat;
                $stat->setDate($when);
                $stat->setMetric($metric);
                $stat->setValue($value);
                $stats[] = $stat;
            }
        }

        $response = new QueryDomainStatsResponse;
        $response->setDomainStats($stats);

        $info = new PostmasterDomain;
        $info->setVerificationState(PostmasterDomain::VERIFICATION_STATE_VERIFIED);
        $compliance = new ComplianceStatus;
        $compliance->setStatus(ComplianceStatus::STATUS_COMPLIANT);

        $domains = Mockery::mock(Domains::class);
        $domainStats = Mockery::mock(DomainsDomainStats::class);

        if ($failure) {
            $domains->shouldReceive('get')->andThrow($failure);
        } else {
            $domains->shouldReceive('get')->andReturn($info);
            $domains->shouldReceive('getComplianceStatus')->andReturn($compliance);
            $domainStats->shouldReceive('query')->andReturn($response);
        }

        $service = Mockery::mock(PostmasterTools::class);
        $service->domains = $domains;
        $service->domains_domainStats = $domainStats;

        $factory = Mockery::mock(GmailClientFactory::class);
        $factory->shouldReceive('postmasterFor')->andReturn($service);
        $this->app->instance(GmailClientFactory::class, $factory);
    }

    public function test_stores_one_row_per_day_and_re_running_does_not_duplicate(): void
    {
        $domain = Domain::factory()->create(['name' => 'acme.test']);
        Mailbox::factory()->withPostmasterScope()->create(['domain_id' => $domain->id]);
        $this->fakePostmaster([
            '2026-09-18' => ['SPAM_RATE' => 0.0012, 'AUTH_SUCCESS_RATE' => 0.99, 'DELIVERY_ERROR_RATE' => 0.0],
            '2026-09-19' => ['SPAM_RATE' => 0.0031, 'AUTH_SUCCESS_RATE' => 1.0],
        ]);

        $result = app(PostmasterSync::class)->sync($domain);
        app(PostmasterSync::class)->sync($domain->fresh());

        $this->assertTrue($result['ok']);
        $this->assertSame(2, PostmasterStat::query()->count());

        $latest = $domain->fresh()->latestPostmasterStat;
        $this->assertSame('2026-09-19', $latest->date->toDateString());
        $this->assertSame(0.0031, $latest->spam_rate);
        $this->assertNull($latest->delivery_error_rate);
        $this->assertSame(0.99, PostmasterStat::query()->where('date', '2026-09-18')->sole()->auth_success_rate);

        $fresh = $domain->fresh();
        $this->assertNotNull($fresh->postmaster_synced_at);
        $this->assertNull($fresh->postmaster_error);
        $this->assertSame('VERIFIED', $fresh->postmaster_verification);
        $this->assertSame('COMPLIANT', $fresh->postmaster_compliance['status'] ?? null);
    }

    public function test_a_domain_google_will_not_show_us_is_reported_in_a_sentence(): void
    {
        $domain = Domain::factory()->create(['name' => 'unverified.test']);
        Mailbox::factory()->withPostmasterScope()->create(['domain_id' => $domain->id, 'email' => 'me@unverified.test']);
        $this->fakePostmaster([], new GoogleException('forbidden', 403));

        $result = app(PostmasterSync::class)->sync($domain);

        $this->assertFalse($result['ok']);
        // Google's own reason is kept: the same 403 means either the account
        // was never given the domain or the API is off in the Cloud project.
        $this->assertStringContainsString('Postmaster Tools refused me@unverified.test', $domain->fresh()->postmaster_error);
        $this->assertStringContainsString('HTTP 403: forbidden', $domain->fresh()->postmaster_error);
        $this->assertStringContainsString('added as a user', $domain->fresh()->postmaster_error);
        $this->assertTrue(ActivityLog::query()->where('event', 'postmaster_sync_failed')->exists());

        // The same failure tomorrow is not a new event.
        app(PostmasterSync::class)->sync($domain->fresh());
        $this->assertSame(1, ActivityLog::query()->where('event', 'postmaster_sync_failed')->count());
    }

    public function test_without_a_scoped_mailbox_it_asks_for_a_reconnect(): void
    {
        $domain = Domain::factory()->create();
        Mailbox::factory()->connected()->create(['domain_id' => $domain->id]);

        $result = app(PostmasterSync::class)->sync($domain);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('reconnect', $domain->fresh()->postmaster_error);
    }

    public function test_the_command_syncs_only_the_domain_asked_for(): void
    {
        $wanted = Domain::factory()->create(['name' => 'wanted.test']);
        $other = Domain::factory()->create(['name' => 'other.test']);
        Mailbox::factory()->withPostmasterScope()->create(['domain_id' => $wanted->id]);
        $this->fakePostmaster(['2026-09-19' => ['SPAM_RATE' => 0.001]]);

        $this->artisan(SyncPostmasterStats::class, ['--domain' => 'wanted.test'])
            ->expectsOutputToContain('wanted.test: 1 day(s)')
            ->assertSuccessful();

        $this->assertNotNull($wanted->fresh()->postmaster_synced_at);
        $this->assertNull($other->fresh()->postmaster_synced_at);
    }
}
