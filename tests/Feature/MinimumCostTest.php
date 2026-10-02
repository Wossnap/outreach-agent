<?php

namespace Tests\Feature;

use App\Livewire\Settings\Waterfall;
use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Models\User;
use App\Services\Enrichment\EmailWaterfall;
use App\Services\Enrichment\MinimumCost;
use App\Services\Enrichment\ProviderHealth;
use App\Support\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CheckerWithCredits;
use Tests\Support\DomainAlwaysAcceptsMail;
use Tests\Support\FakeEnrichment;
use Tests\Support\FinderWithCredits;
use Tests\Support\FindsNothing;
use Tests\Support\SaysValid;
use Tests\TestCase;

/**
 * One switch for the cheapest setup: Hunter, Reoon and BounceBan, with every
 * other provider locked off rather than left looking switched on.
 */
class MinimumCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeEnrichment::reset();
        $this->app->bind(DnsResolver::class, DomainAlwaysAcceptsMail::class);

        // The real driver keys, so the switch recognises the three it keeps.
        config(['enrichment.drivers' => [
            'findymail' => FindsNothing::class,
            'hunter' => FinderWithCredits::class,
            'reoon' => SaysValid::class,
            'bounceban' => CheckerWithCredits::class,
            'zerobounce' => SaysValid::class,
        ]]);
    }

    private function provider(string $driver, int $position, array $attributes = []): EnrichmentProvider
    {
        return EnrichmentProvider::create(array_merge([
            'name' => ucfirst($driver),
            'driver' => $driver,
            'position' => $position,
            'enabled' => true,
            'credentials' => ['api_key' => 'test-key'],
        ], $attributes));
    }

    /** The full waterfall: two finders, two verifiers, all switched on. */
    private function everythingOn(): void
    {
        $this->provider('findymail', 1);
        $this->provider('hunter', 2);
        $this->provider('zerobounce', 1);
        $this->provider('reoon', 2);
        $this->provider('bounceban', 3);
    }

    public function test_it_is_off_until_somebody_turns_it_on(): void
    {
        $this->everythingOn();

        $this->assertFalse(MinimumCost::isOn());
        $this->assertSame(5, EnrichmentProvider::query()->where('enabled', true)->count());
    }

    public function test_turning_it_on_locks_off_everything_but_hunter_reoon_and_bounceban(): void
    {
        $this->everythingOn();

        $locked = MinimumCost::switchOn();

        $this->assertEqualsCanonicalizing(['findymail', 'zerobounce'], $locked->pluck('driver')->all());
        $this->assertEqualsCanonicalizing(
            ['hunter', 'reoon', 'bounceban'],
            EnrichmentProvider::query()->where('enabled', true)->pluck('driver')->all(),
        );

        // And the waterfall really does skip them: Findymail is first in line
        // but is never asked.
        app(EmailWaterfall::class)->run(Contact::create(['name' => 'Sam Carter', 'domain' => 'acme.com']));
        $this->assertNotContains('finds-nothing', FakeEnrichment::$calls);
    }

    public function test_turning_it_off_puts_every_provider_back_exactly_as_it_was(): void
    {
        $this->everythingOn();
        EnrichmentProvider::query()->where('driver', 'zerobounce')->first()->update(['enabled' => false]);
        EnrichmentProvider::query()->where('driver', 'findymail')->first()->disableBecause('5 calls in a row failed.');

        MinimumCost::switchOn();
        MinimumCost::switchOff();

        $findymail = EnrichmentProvider::query()->where('driver', 'findymail')->first();
        $this->assertFalse($findymail->enabled);
        $this->assertSame('5 calls in a row failed.', $findymail->disabled_reason);
        $this->assertNotNull($findymail->disabled_at);
        $this->assertFalse(EnrichmentProvider::query()->where('driver', 'zerobounce')->first()->enabled);
        $this->assertFalse(MinimumCost::isOn());
    }

    public function test_a_locked_provider_cannot_be_switched_on_from_the_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->everythingOn();
        MinimumCost::switchOn();
        $findymail = EnrichmentProvider::query()->where('driver', 'findymail')->first();

        Livewire::test(Waterfall::class)
            ->assertSee('locked off')
            ->call('toggle', $findymail->id)
            ->assertSet('flash', 'Findymail is locked off while Minimum cost is on. Turn Minimum cost off to use it.');

        $this->assertFalse($findymail->fresh()->enabled);
    }

    public function test_the_page_switch_turns_it_on_and_off(): void
    {
        $this->actingAs(User::factory()->create());
        $this->everythingOn();

        Livewire::test(Waterfall::class)->call('toggleMinimumCost');
        $this->assertTrue(MinimumCost::isOn());
        $this->assertFalse(EnrichmentProvider::query()->where('driver', 'findymail')->first()->enabled);

        Livewire::test(Waterfall::class)->call('toggleMinimumCost');
        $this->assertFalse(MinimumCost::isOn());
        $this->assertTrue(EnrichmentProvider::query()->where('driver', 'findymail')->first()->enabled);
    }

    public function test_the_hourly_check_never_switches_a_locked_provider_back_on(): void
    {
        // Findymail ran dry and was switched off for it, then Minimum cost was
        // turned on. It gets credit back: it must stay off while locked.
        $this->everythingOn();
        EnrichmentProvider::query()->where('driver', 'findymail')->first()->disableBecause('5 calls in a row failed.');
        MinimumCost::switchOn();

        $health = app(ProviderHealth::class);
        $health->refreshBalances();
        $health->reviveTopUps();

        $this->assertFalse(EnrichmentProvider::query()->where('driver', 'findymail')->first()->enabled);
    }

    public function test_the_page_says_when_a_provider_it_needs_is_not_ready(): void
    {
        $this->actingAs(User::factory()->create());
        $this->everythingOn();
        EnrichmentProvider::query()->where('driver', 'bounceban')->first()->update(['credentials' => null]);
        MinimumCost::switchOn();

        Livewire::test(Waterfall::class)->assertSee('Minimum cost needs Bounceban');
    }
}
