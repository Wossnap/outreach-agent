<?php

namespace Tests\Feature;

use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Drivers\BounceBanVerifier;
use App\Services\Enrichment\Drivers\FindymailFinder;
use App\Services\Enrichment\Drivers\HunterFinder;
use App\Services\Enrichment\Drivers\MyEmailVerifierVerifier;
use App\Services\Enrichment\Drivers\ReoonVerifier;
use App\Services\Enrichment\Drivers\ZeroBounceVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Reading what a provider has left.
 *
 * Every response body below is a real one, captured from the live account on
 * 4 September 2026 and pasted in unchanged. The three providers that report two
 * pools are the reason this exists, so those three are tested with the pools
 * set to different numbers: a test where both pools read 24 would pass with the
 * wrong one picked.
 */
class ProviderBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function provider(string $driver, string $kind = EnrichmentProvider::KIND_VERIFY): EnrichmentProvider
    {
        return EnrichmentProvider::create([
            'name' => $driver,
            'driver' => $driver,
            'kind' => $kind,
            'credentials' => ['api_key' => 'test-key'],
        ]);
    }

    // ---------------------------------------------------------------- pools

    public function test_hunter_reports_searches_not_verifications(): void
    {
        Http::fake(['api.hunter.io/v2/account*' => Http::response([
            'data' => [
                'plan_name' => 'Free',
                'requests' => [
                    'credits' => ['used' => 2.0, 'available' => 50.0, 'remaining' => 48.0],
                    'searches' => ['used' => 2, 'available' => 50, 'remaining' => 48],
                    'verifications' => ['used' => 4, 'available' => 100, 'remaining' => 96],
                ],
                'calls' => ['used' => 20, 'available' => 75],
            ],
        ])]);

        $balance = (new HunterFinder)->balance($this->provider('hunter', EnrichmentProvider::KIND_FIND));

        // We only ever search with Hunter, so the 96 verifications are credit
        // this system cannot spend.
        $this->assertSame(48, $balance->remaining);
        $this->assertSame('searches', $balance->pool);
        $this->assertSame(['verifications' => 96], $balance->untouched);
    }

    public function test_hunter_reads_searches_rather_than_the_credits_field(): void
    {
        /*
         * Invented, unlike the rest of the bodies here, and deliberately so.
         * On the live account "credits" and "searches" both read 48, so a test
         * built on the real response passes whichever of the two is read. They
         * are not documented to stay equal, and only one of them is what an
         * email-finder call spends, so they are pulled apart here.
         */
        Http::fake(['api.hunter.io/v2/account*' => Http::response([
            'data' => [
                'requests' => [
                    'credits' => ['remaining' => 999],
                    'searches' => ['remaining' => 48],
                    'verifications' => ['remaining' => 96],
                ],
                'calls' => ['used' => 20, 'available' => 75],
            ],
        ])]);

        $balance = (new HunterFinder)->balance($this->provider('hunter', EnrichmentProvider::KIND_FIND));

        $this->assertSame(48, $balance->remaining);
    }

    public function test_findymail_reports_finder_credits_not_verifier_credits(): void
    {
        Http::fake(['app.findymail.com/api/credits' => Http::response([
            'credits' => 24, 'pricing' => 'variant_a', 'id' => 97167, 'verifier_credits' => 10,
        ])]);

        $balance = (new FindymailFinder)->balance($this->provider('findymail', EnrichmentProvider::KIND_FIND));

        $this->assertSame(24, $balance->remaining);
        $this->assertSame('finder credits', $balance->pool);
        $this->assertSame(['verifier credits' => 10], $balance->untouched);
    }

    public function test_reoon_reports_instant_credits_because_we_run_power_mode(): void
    {
        Http::fake(['emailverifier.reoon.com/api/v1/check-account-balance*' => Http::response([
            'api_status' => 'active',
            'remaining_daily_credits' => 14,
            'remaining_instant_credits' => 100,
            'status' => 'success',
        ])]);

        $balance = (new ReoonVerifier)->balance($this->provider('reoon'));

        // Daily credits are what quick mode spends. We hardcode power mode.
        $this->assertSame(100, $balance->remaining);
        $this->assertSame('instant credits', $balance->pool);
        $this->assertSame(['daily credits' => 14], $balance->untouched);
    }

    public function test_the_untouched_pool_is_named_in_the_description(): void
    {
        Http::fake(['emailverifier.reoon.com/api/v1/check-account-balance*' => Http::response([
            'api_status' => 'active',
            'remaining_daily_credits' => 14,
            'remaining_instant_credits' => 100,
            'status' => 'success',
        ])]);

        $description = (new ReoonVerifier)->balance($this->provider('reoon'))->describe();

        $this->assertSame('instant credits, plus 14 daily credits we never spend', $description);
    }

    // ------------------------------------------------------- single pools

    public function test_bounceban_reads_its_account_endpoint(): void
    {
        Http::fake(['api.bounceban.com/v1/account' => Http::response([
            'available_credits' => 95,
            'owner_email' => 'someone@example.com',
            'rate_limit' => [['api' => '/account', 'limit' => '5 per second']],
        ])]);

        $balance = (new BounceBanVerifier)->balance($this->provider('bounceban'));

        $this->assertSame(95, $balance->remaining);
        $this->assertSame([], $balance->untouched);
        $this->assertSame('credits', $balance->describe());
    }

    public function test_myemailverifier_reads_its_credits(): void
    {
        Http::fake(['client.myemailverifier.com/verifier/getcredits/*' => Http::response([
            'status' => true, 'credits' => 97,
        ])]);

        $this->assertSame(97, (new MyEmailVerifierVerifier)->balance($this->provider('myemailverifier'))->remaining);
    }

    public function test_zerobounce_reads_a_balance_it_sends_as_a_string(): void
    {
        Http::fake(['api.zerobounce.net/v2/getcredits*' => Http::response(['Credits' => '3'])]);

        $this->assertSame(3, (new ZeroBounceVerifier)->balance($this->provider('zerobounce'))->remaining);
    }

    // ------------------------------------------------ refusals wearing a 200

    public function test_zerobounce_minus_one_is_a_rejected_key_not_an_empty_account(): void
    {
        // Their answer to a key they do not recognise: HTTP 200, and a number
        // in exactly the shape of a real balance.
        Http::fake(['api.zerobounce.net/v2/getcredits*' => Http::response(['Credits' => '-1'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not recognise/');

        (new ZeroBounceVerifier)->balance($this->provider('zerobounce'));
    }

    public function test_myemailverifier_login_page_is_not_read_as_an_account(): void
    {
        // A wrong key is answered with 200 and a redirect to their login form.
        Http::fake(['client.myemailverifier.com/*' => Http::response(
            '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=\'https://client.myemailverifier.com/login\'" /></head></html>'
        )]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not accept the key/');

        (new MyEmailVerifierVerifier)->balance($this->provider('myemailverifier'));
    }

    public function test_reoon_refuses_to_report_a_balance_for_an_inactive_key(): void
    {
        Http::fake(['emailverifier.reoon.com/api/v1/check-account-balance*' => Http::response([
            'api_status' => 'inactive', 'remaining_instant_credits' => 100, 'status' => 'success',
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rather than active/');

        (new ReoonVerifier)->balance($this->provider('reoon'));
    }

    public function test_a_missing_figure_is_an_error_rather_than_a_zero(): void
    {
        // The field renamed, or the shape changed. Cast to an integer that is
        // zero, and a working account would report as used up.
        Http::fake(['api.bounceban.com/v1/account' => Http::response(['owner_email' => 'someone@example.com'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not report a balance/');

        (new BounceBanVerifier)->balance($this->provider('bounceban'));
    }

    public function test_an_http_failure_is_an_error(): void
    {
        Http::fake(['api.bounceban.com/v1/account' => Http::response('nope', 500)]);

        $this->expectException(RuntimeException::class);

        (new BounceBanVerifier)->balance($this->provider('bounceban'));
    }
}
