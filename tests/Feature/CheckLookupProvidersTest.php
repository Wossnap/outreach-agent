<?php

namespace Tests\Feature;

use App\Livewire\Contacts\Index;
use App\Livewire\Dashboard;
use App\Models\ActivityLog;
use App\Models\EnrichmentProvider;
use App\Models\User;
use App\Notifications\LookupsNeedAttention;
use App\Services\Enrichment\EnrichmentSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CheckerWithCredits;
use Tests\Support\FakeEnrichment;
use Tests\Support\FinderWithCredits;
use Tests\TestCase;

/**
 * A finder or checker running dry used to be visible only to somebody who
 * opened the Email waterfall page, and leads sat at Pending for a week. Now
 * it is said where leads are looked at, emailed once, and a provider that is
 * topped up comes back by itself.
 */
class CheckLookupProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeEnrichment::reset();
        Notification::fake();

        config([
            'enrichment.drivers' => [
                'finder' => FinderWithCredits::class,
                'checker' => CheckerWithCredits::class,
            ],
            'enrichment.alerts.to' => ['sean@example.com'],
            'enrichment.alerts.low_credits' => 50,
        ]);
    }

    private function provider(string $driver, array $attributes = []): EnrichmentProvider
    {
        return EnrichmentProvider::create(array_merge([
            'name' => ucfirst($driver),
            'driver' => $driver,
            'position' => 1,
            'enabled' => true,
            'credentials' => ['api_key' => 'test-key'],
        ], $attributes));
    }

    private function switchedOffByTheWaterfall(string $driver): EnrichmentProvider
    {
        $provider = $this->provider($driver);
        $provider->disableBecause('5 calls in a row failed. Check the key and the account balance.');

        return $provider;
    }

    private function emailsSent(): int
    {
        return Notification::sent(new AnonymousNotifiable, LookupsNeedAttention::class)->count();
    }

    public function test_a_provider_switched_off_for_failing_comes_back_once_topped_up(): void
    {
        $finder = $this->switchedOffByTheWaterfall('finder');

        $this->artisan('enrichment:check-providers')->assertSuccessful();

        $finder->refresh();
        $this->assertTrue($finder->enabled);
        $this->assertNull($finder->disabled_reason);
        // The balance it was switched back on for is still shown, not
        // "not checked yet": switching it on must not throw the reading away.
        $this->assertSame(100, $finder->cachedBalance()?->balance?->remaining);
        $this->assertSame(1, ActivityLog::query()->where('event', 'provider_switched_back_on')->count());
    }

    public function test_a_provider_still_out_of_credit_stays_off(): void
    {
        FakeEnrichment::$credits = ['finder' => 0];
        $finder = $this->switchedOffByTheWaterfall('finder');

        $this->artisan('enrichment:check-providers')->assertSuccessful();

        $this->assertFalse($finder->fresh()->enabled);
    }

    public function test_a_provider_somebody_switched_off_by_hand_stays_off(): void
    {
        // ZeroBounce, switched off on purpose to save the specialist's
        // budget. It has credit, and it must not come back.
        $checker = $this->provider('checker', ['enabled' => false]);

        $this->artisan('enrichment:check-providers')->assertSuccessful();

        $this->assertFalse($checker->fresh()->enabled);
    }

    public function test_a_new_problem_is_emailed_once_and_again_only_if_it_comes_back(): void
    {
        $this->provider('checker');
        $finder = $this->provider('finder');
        FakeEnrichment::$credits = ['finder' => 12];

        $this->artisan('enrichment:check-providers')->assertSuccessful();

        Notification::assertSentOnDemand(
            LookupsNeedAttention::class,
            fn (LookupsNeedAttention $mail, array $channels, object $notifiable) => $notifiable->routes['mail'] === ['sean@example.com']
                && in_array('Finder is running low: 12 credits left.', $mail->alerts, true),
        );

        // Still low an hour later: already said, not said again.
        $this->artisan('enrichment:check-providers')->assertSuccessful();
        $this->assertSame(1, $this->emailsSent());

        // Topped up, then low again: a new occurrence, so a new email.
        FakeEnrichment::$credits = ['finder' => 500];
        $this->artisan('enrichment:check-providers')->assertSuccessful();
        FakeEnrichment::$credits = ['finder' => 3];
        $this->artisan('enrichment:check-providers')->assertSuccessful();

        $this->assertSame(2, $this->emailsSent());
        $this->assertTrue($finder->fresh()->enabled);
    }

    public function test_every_dashboard_user_is_told_when_nobody_is_named(): void
    {
        config(['enrichment.alerts.to' => []]);
        User::factory()->create(['email' => 'owner@example.com']);
        $this->provider('checker');

        $this->artisan('enrichment:check-providers')->assertSuccessful();

        Notification::assertSentOnDemand(
            LookupsNeedAttention::class,
            fn (LookupsNeedAttention $mail, array $channels, object $notifiable) => $notifiable->routes['mail'] === ['owner@example.com']
                && in_array('No email finder is switched on. Leads without an address will wait to retry until one is.', $mail->alerts, true),
        );
    }

    public function test_nothing_is_said_when_all_is_well_or_lookups_are_switched_off(): void
    {
        $this->provider('finder');
        $this->provider('checker');

        $this->artisan('enrichment:check-providers')->assertSuccessful();
        $this->assertSame(0, $this->emailsSent());

        EnrichmentProvider::query()->update(['enabled' => false]);
        EnrichmentSwitch::turnOff();

        $this->artisan('enrichment:check-providers')->assertSuccessful();
        $this->assertSame(0, $this->emailsSent());
    }

    public function test_the_warning_shows_on_leads_and_dashboard(): void
    {
        $this->actingAs(User::factory()->create());
        $this->provider('checker');
        $this->switchedOffByTheWaterfall('finder');
        FakeEnrichment::$credits = ['finder' => 0];

        foreach ([Index::class, Dashboard::class] as $page) {
            Livewire::test($page)
                ->assertSeeHtml('data-lookup-alerts')
                ->assertSee('No email finder is switched on.')
                ->assertSee('Finder was switched off: 5 calls in a row failed.');
        }
    }

    public function test_no_warning_when_all_is_well(): void
    {
        $this->actingAs(User::factory()->create());
        $this->provider('finder');
        $this->provider('checker');

        foreach ([Index::class, Dashboard::class] as $page) {
            Livewire::test($page)->assertDontSeeHtml('data-lookup-alerts');
        }
    }
}
