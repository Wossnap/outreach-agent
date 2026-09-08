<?php

namespace Tests\Feature;

use App\Models\EnrichmentProvider;
use App\Services\Enrichment\FreeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What comes back when a provider is asked for its balance.
 *
 * The drivers are tested separately for reading a response. This is about the
 * four different things a blank can mean, and about the page never asking
 * anybody by itself: opening it used to make six live API calls and wait on
 * the slowest of them.
 */
class ProviderBalanceReadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function provider(array $attributes = []): EnrichmentProvider
    {
        return EnrichmentProvider::create(array_merge([
            'name' => 'BounceBan',
            'driver' => 'bounceban',
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'credentials' => ['api_key' => 'test-key'],
        ], $attributes));
    }

    private function fakeCredits(int $credits): void
    {
        Http::fake(['api.bounceban.com/v1/account' => Http::response(['available_credits' => $credits])]);
    }

    public function test_a_provider_with_no_key_is_not_asked(): void
    {
        // Nothing to ask with. preventStrayRequests would fail this if it tried.
        $this->assertNull($this->provider(['credentials' => null])->checkBalance());
    }

    public function test_a_driver_with_no_balance_endpoint_reports_nothing(): void
    {
        // A class that exists and is not a ReportsBalance.
        config(['enrichment.drivers.freegate' => FreeGate::class]);

        $this->assertFalse(EnrichmentProvider::reportsBalanceForDriver('freegate'));
        $this->assertFalse(EnrichmentProvider::reportsBalanceForDriver('nothing-registered-here'));
    }

    public function test_every_driver_we_ship_can_report_a_balance(): void
    {
        foreach (array_keys(config('enrichment.drivers')) as $driver) {
            $this->assertTrue(
                EnrichmentProvider::reportsBalanceForDriver($driver),
                "[{$driver}] cannot report a balance.",
            );
        }
    }

    public function test_a_failing_provider_costs_its_own_row_and_not_the_page(): void
    {
        Http::fake(['api.bounceban.com/v1/account' => Http::response('down', 503)]);

        $reading = $this->provider()->checkBalance();

        // The failure comes back rather than being thrown, so asking the other
        // five still works.
        $this->assertFalse($reading->succeeded());
        $this->assertNull($reading->balance);
        $this->assertStringContainsString('503', $reading->error);
    }

    public function test_a_failed_check_does_not_switch_the_provider_off(): void
    {
        Http::fake(['api.bounceban.com/v1/account' => Http::response('down', 503)]);

        $provider = $this->provider(['enabled' => true]);
        $provider->checkBalance();

        // Being unable to read a billing endpoint says nothing about whether
        // the provider can verify an address.
        $this->assertTrue($provider->fresh()->enabled);
        $this->assertNull($provider->fresh()->disabled_reason);
    }

    public function test_reading_the_stored_figure_never_calls_anybody(): void
    {
        $this->fakeCredits(95);
        $provider = $this->provider();

        $provider->checkBalance();

        // The page draws from this, six times over, and must not add a call.
        // preventStrayRequests is not enough on its own here: the stub would
        // happily answer, so the count is what proves it.
        $provider->cachedBalance();
        $provider->cachedBalance();
        $provider->cachedBalance();

        Http::assertSentCount(1);
    }

    public function test_nobody_having_asked_yet_is_not_the_same_as_a_figure(): void
    {
        // What every row says on a fresh install: no call is made to find out,
        // and null is the page's cue to say "not checked yet" rather than to
        // print a zero.
        $this->assertNull($this->provider()->cachedBalance());
    }

    public function test_a_stored_figure_does_not_go_stale_on_its_own(): void
    {
        $this->fakeCredits(95);
        $provider = $this->provider();
        $provider->checkBalance();

        // It used to be forgotten after ten minutes, which only meant the next
        // person to open the page paid for the call. It is kept until somebody
        // presses the button again, and the page says how old it is.
        $this->travel(3)->weeks();

        $this->assertSame(95, $provider->cachedBalance()->balance->remaining);
        Http::assertSentCount(1);
    }

    public function test_editing_a_provider_throws_away_its_remembered_balance(): void
    {
        $this->fakeCredits(95);
        $provider = $this->provider();
        $provider->checkBalance();

        // Somebody pastes in a corrected key. They should not be shown the
        // failure they came to fix; the row reads as never asked instead.
        $provider->update(['credentials' => ['api_key' => 'a-better-key']]);

        $this->assertNull($provider->cachedBalance());
    }

    public function test_nothing_of_ours_goes_into_the_cache(): void
    {
        /*
         * The bug this exists to stop, which shipped once.
         *
         * PHP only autoloads during unserialize() when unserialize_callback_func
         * is set, and it is empty in the stock php.ini on the server. So an
         * object read back by a process that has not already loaded its class
         * arrives as __PHP_Incomplete_Class, and the page 500s. Warming the
         * cache and reading it back in the same process cannot show this,
         * because the class is already loaded there: that is exactly how the
         * broken version passed a check against the live server.
         *
         * Asserting on the serialized form instead. No "O:" means no object,
         * so there is nothing for unserialize to have to load.
         */
        $this->fakeCredits(95);
        $provider = $this->provider();
        $provider->checkBalance();

        $cached = Cache::get("enrichment.balance.{$provider->id}");

        $this->assertIsArray($cached);
        $this->assertStringNotContainsString('O:', serialize($cached));
    }

    public function test_a_reading_it_cannot_make_sense_of_is_read_again(): void
    {
        // What a deploy leaves behind when the stored shape changes. It must
        // self-heal, or every page stays broken until the cache is cleared.
        $provider = $this->provider();
        Cache::put("enrichment.balance.{$provider->id}", 'left by an older version', 600);

        $this->fakeCredits(95);

        $this->assertSame(95, $provider->checkBalance()->balance->remaining);
        $this->assertSame(95, $provider->cachedBalance()->balance->remaining);
    }

    public function test_a_reading_survives_the_cache_intact(): void
    {
        Http::fake(['emailverifier.reoon.com/api/v1/check-account-balance*' => Http::response([
            'api_status' => 'active',
            'remaining_daily_credits' => 14,
            'remaining_instant_credits' => 100,
        ])]);

        $provider = $this->provider(['name' => 'Reoon', 'driver' => 'reoon']);
        $provider->checkBalance();

        // Read back from the cache this time, not built fresh.
        $reading = $provider->cachedBalance();

        $this->assertSame(100, $reading->balance->remaining);
        $this->assertSame('instant credits', $reading->balance->pool);
        $this->assertSame(['daily credits' => 14], $reading->balance->untouched);
        $this->assertNotNull($reading->checkedAt);
        Http::assertSentCount(1);
    }

    public function test_an_empty_account_is_distinguishable_from_a_healthy_one(): void
    {
        /*
         * A sequence rather than two calls to fake(): a second fake() is added
         * behind the first rather than replacing it, and the first stub that
         * matches wins, so the second response would never have been served.
         */
        Http::fake([
            'api.bounceban.com/v1/account' => Http::sequence()
                ->push(['available_credits' => 0])
                ->push(['available_credits' => 95]),
        ]);

        $provider = $this->provider();

        $this->assertTrue($provider->checkBalance()->balance->isEmpty());

        // Pressing the button again, after topping the account up.
        $this->assertFalse($provider->checkBalance()->balance->isEmpty());
    }
}
