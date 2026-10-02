<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepriceLookupsTest extends TestCase
{
    use RefreshDatabase;

    private function lookup(string $driver, string $kind, string $result, float $cost): EmailLookup
    {
        return EmailLookup::create([
            'contact_id' => Contact::factory()->create()->id,
            'provider_name' => ucfirst($driver), 'driver' => $driver,
            'kind' => $kind, 'result' => $result, 'cost' => $cost,
        ]);
    }

    public function test_it_reports_without_changing_anything_unless_told_to_apply(): void
    {
        $found = $this->lookup('hunter', 'find', 'found', 0.098);

        $this->artisan('enrichment:reprice-lookups')
            ->expectsOutputToContain('Nothing changed: run again with --apply.')
            ->assertSuccessful();

        $this->assertSame('0.098000', $found->fresh()->cost);
    }

    public function test_applied_every_past_lookup_is_repriced_by_the_rule_new_calls_follow(): void
    {
        $hunterFound = $this->lookup('hunter', 'find', 'found', 0.098);
        $hunterNothing = $this->lookup('hunter', 'find', 'nothing', 0);
        $hunterFailed = $this->lookup('hunter', 'find', 'error', 0);
        $findymailFound = $this->lookup('findymail', 'find', 'found', 0.049);
        $reoonValid = $this->lookup('reoon', 'verify', 'valid', 0.0012);
        $reoonCatchAll = $this->lookup('reoon', 'verify', 'catch_all', 0.0012);
        $zeroBounceInvalid = $this->lookup('zerobounce', 'verify', 'invalid', 0.016);
        $freeGate = $this->lookup(EmailLookup::DRIVER_FREE_GATE, 'verify', 'invalid', 0);

        $this->artisan('enrichment:reprice-lookups --apply')->assertSuccessful();

        $this->assertSame('0.024500', $hunterFound->fresh()->cost);
        $this->assertSame('0.000000', $hunterNothing->fresh()->cost, 'Hunter does not charge when it finds nothing');
        $this->assertSame('0.000000', $hunterFailed->fresh()->cost, 'a failed call is never charged');
        $this->assertSame('0.019800', $findymailFound->fresh()->cost);
        $this->assertSame('0.001190', $reoonValid->fresh()->cost);
        $this->assertSame('0.001190', $reoonCatchAll->fresh()->cost, 'Reoon charges for every check');
        $this->assertSame('0.019500', $zeroBounceInvalid->fresh()->cost);
        $this->assertSame('0.000000', $freeGate->fresh()->cost, 'the free gate is not a provider');
    }
}
