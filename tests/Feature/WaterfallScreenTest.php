<?php

namespace Tests\Feature;

use App\Jobs\EnrichContact;
use App\Livewire\Contacts\Index as LeadsIndex;
use App\Livewire\Settings\Waterfall;
use App\Livewire\Settings\WaterfallPerformance;
use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use App\Models\User;
use App\Services\Enrichment\EnrichmentSwitch;
use App\Services\Sending\VerifiedEmailSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens that make the waterfall usable.
 *
 * Every provider ships switched off and without a key, so nothing runs until
 * somebody configures it on these screens.
 */
class WaterfallScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_both_settings_pages_render(): void
    {
        $this->get('/settings/waterfall')->assertOk();
        $this->get('/settings/spend')->assertOk();
    }

    public function test_the_leads_page_is_served_where_a_person_would_look(): void
    {
        // Contacts in code, leads on screen. The URL is read by a person.
        $this->get('/leads')->assertOk()->assertSee('Leads');
    }

    public function test_every_provider_we_have_written_code_for_appears_by_itself(): void
    {
        $this->assertSame(0, EnrichmentProvider::query()->count());

        Livewire::test(Waterfall::class)->assertSee('Hunter')->assertSee('Reoon');

        // Providers are chosen from the ones we support, never invented, so
        // there is nothing to create and no way to create one pointing nowhere.
        $this->assertSame(
            count(config('enrichment.drivers')),
            EnrichmentProvider::query()->count(),
        );

        // And every one of them is inert until somebody puts a key in.
        $this->assertSame(0, EnrichmentProvider::query()->where('enabled', true)->count());
    }

    public function test_a_key_is_stored_encrypted_and_never_shown_back(): void
    {
        Livewire::test(Waterfall::class);
        $provider = EnrichmentProvider::query()->where('driver', 'reoon')->sole();

        Livewire::test(Waterfall::class)
            ->call('edit', $provider->id)
            ->set('apiKey', 'a-real-key')
            ->call('saveKey')
            ->assertSet('apiKey', '')
            ->assertSet('editing', null);

        $this->assertSame('a-real-key', $provider->fresh()->credential('api_key'));

        // Encrypted at rest: there is no reason for anyone reading the database
        // to see a live billing credential.
        $stored = DB::table('enrichment_providers')->find($provider->id)->credentials;
        $this->assertStringNotContainsString('a-real-key', $stored);
    }

    public function test_a_provider_with_no_key_cannot_be_switched_on(): void
    {
        Livewire::test(Waterfall::class);
        $provider = EnrichmentProvider::query()->where('driver', 'hunter')->sole();

        Livewire::test(Waterfall::class)
            ->call('toggle', $provider->id)
            ->assertSee('has no key yet');

        // Otherwise it fails every call and switches itself off for failing,
        // which reads as a broken provider rather than an empty field.
        $this->assertFalse($provider->fresh()->enabled);
    }

    public function test_giving_a_key_clears_what_it_was_switched_off_for(): void
    {
        Livewire::test(Waterfall::class);
        $provider = EnrichmentProvider::query()->where('driver', 'reoon')->sole();
        $provider->disableBecause('5 calls in a row failed.');

        Livewire::test(Waterfall::class)
            ->call('edit', $provider->id)
            ->set('apiKey', 'a-corrected-key')
            ->call('saveKey');

        // The message described a failure somebody has just come to fix.
        $this->assertNull($provider->fresh()->disabled_reason);
    }

    public function test_moving_a_provider_reorders_only_its_own_half_of_the_chain(): void
    {
        Livewire::test(Waterfall::class);

        $verifiers = EnrichmentProvider::query()
            ->where('kind', EnrichmentProvider::KIND_VERIFY)
            ->orderBy('position')->get();
        $second = $verifiers[1];

        Livewire::test(Waterfall::class)->call('move', $second->id, 'up');

        $reordered = EnrichmentProvider::query()
            ->where('kind', EnrichmentProvider::KIND_VERIFY)
            ->orderBy('position')->pluck('id');

        $this->assertSame($second->id, $reordered->first());

        // The finders are numbered from 1 and the verifiers from 1000, so the
        // two chains can never interleave however they are reordered.
        $finders = EnrichmentProvider::query()->where('kind', EnrichmentProvider::KIND_FIND)->pluck('position');
        $this->assertTrue($finders->every(fn (int $p): bool => $p < 1000));
    }

    public function test_the_two_switches_can_be_thrown_from_the_page(): void
    {
        Livewire::test(Waterfall::class)->call('toggleEnrichment');
        $this->assertTrue(EnrichmentSwitch::isOff());

        Livewire::test(Waterfall::class)->call('toggleVerifiedEmail');
        $this->assertTrue(VerifiedEmailSwitch::isOff());
    }

    public function test_drawing_the_waterfall_page_asks_no_provider_anything(): void
    {
        // The whole reason the figures moved behind a button. Six live calls to
        // other companies on every render meant opening the page waited on the
        // slowest of them, and one having a bad afternoon made the page look
        // broken. preventStrayRequests turns any such call into a failure.
        Http::preventStrayRequests();

        EnrichmentProvider::syncWithDrivers();

        // One at a time: a mass update goes straight to the query builder and
        // skips the encrypted cast, storing plain text the reader cannot open.
        EnrichmentProvider::all()->each->update(['credentials' => ['api_key' => 'a-key']]);

        Livewire::test(Waterfall::class)->assertOk()->assertSee('Credits not checked yet');
    }

    public function test_the_button_asks_every_provider_that_has_a_key(): void
    {
        Http::fake(['api.bounceban.com/v1/account' => Http::response(['available_credits' => 42])]);

        EnrichmentProvider::syncWithDrivers();

        $bounceban = EnrichmentProvider::query()->where('driver', 'bounceban')->firstOrFail();
        $bounceban->update(['credentials' => ['api_key' => 'a-key']]);

        Livewire::test(Waterfall::class)
            ->call('checkBalances')
            // The other five have no key, so nobody was asked for them: a
            // stray call would have failed against the single stub above.
            ->assertSee('Asked 1 provider(s)')
            ->assertSee('5 had no key and were not asked')
            ->assertSee('42');

        $this->assertSame(42, $bounceban->cachedBalance()->balance->remaining);
    }

    public function test_a_provider_that_cannot_be_reached_costs_only_its_own_row(): void
    {
        Http::fake([
            'api.bounceban.com/v1/account' => Http::response('down', 503),
            'client.myemailverifier.com/verifier/getcredits/*' => Http::response(['status' => true, 'credits' => 90]),
        ]);

        EnrichmentProvider::syncWithDrivers();
        EnrichmentProvider::query()
            ->whereIn('driver', ['bounceban', 'myemailverifier'])
            ->get()
            ->each
            ->update(['credentials' => ['api_key' => 'a-key']]);

        Livewire::test(Waterfall::class)
            ->call('checkBalances')
            ->assertSee('Asked 2 provider(s); 1 could not be reached')
            // The one that answered still shows its figure.
            ->assertSee('90');
    }

    public function test_nothing_to_ask_says_so_rather_than_looking_broken(): void
    {
        Http::preventStrayRequests();

        EnrichmentProvider::syncWithDrivers();

        Livewire::test(Waterfall::class)
            ->call('checkBalances')
            ->assertSee('No provider has a key yet');
    }

    public function test_the_performance_page_reports_cost_per_answer_not_per_call(): void
    {
        $contact = Contact::factory()->create();

        // Ten calls, one answer, at a penny a call. Cheap per call and dear per
        // address found, which is the whole point of the page.
        foreach (range(1, 10) as $i) {
            EmailLookup::create([
                'contact_id' => $contact->id,
                'provider_name' => 'Cheap and useless',
                'driver' => 'cheap',
                'kind' => EnrichmentProvider::KIND_FIND,
                'result' => $i === 1 ? EmailLookup::RESULT_FOUND : EmailLookup::RESULT_NOTHING,
                'cost' => 0.01,
            ]);
        }

        Livewire::test(WaterfallPerformance::class)
            ->assertSee('Cheap and useless')
            ->assertSee('$0.1000')   // spent
            ->assertSee('$0.1000');  // and all of it for the single answer
    }

    public function test_the_leads_page_filters_by_what_we_know_of_the_address(): void
    {
        Contact::factory()->create(['email' => 'good@acme.com', 'email_status' => Contact::EMAIL_VALID]);
        Contact::factory()->create(['email' => 'bad@acme.com', 'email_status' => Contact::EMAIL_INVALID]);

        Livewire::test(LeadsIndex::class)
            ->set('emailStatuses', [Contact::EMAIL_VALID])
            ->assertSee('good@acme.com')
            ->assertDontSee('bad@acme.com');
    }

    public function test_a_lead_can_be_sent_back_through_the_waterfall(): void
    {
        Queue::fake();
        $contact = Contact::factory()->create(['email_status' => Contact::EMAIL_NOT_FOUND]);

        Livewire::test(LeadsIndex::class)->call('recheck', $contact->id);

        // Settled leads are never revisited on their own, so this is the only
        // way one gets another go after a provider is added or reordered.
        $this->assertSame(Contact::EMAIL_PENDING, $contact->fresh()->email_status);
        Queue::assertPushed(EnrichContact::class);
    }

    public function test_rechecking_says_so_when_nothing_would_happen(): void
    {
        Queue::fake();
        EnrichmentSwitch::turnOff();
        $contact = Contact::factory()->create(['email_status' => Contact::EMAIL_NOT_FOUND]);

        Livewire::test(LeadsIndex::class)
            ->call('recheck', $contact->id)
            ->assertSee('Enrichment is switched off');

        Queue::assertNotPushed(EnrichContact::class);
        $this->assertSame(Contact::EMAIL_NOT_FOUND, $contact->fresh()->email_status);
    }

    /**
     * Spend lists what somebody charged us for, and nothing else.
     *
     * The free syntax and DNS check and a bounce are both recorded as lookups,
     * so they appear on a lead's own history. Grouped into the provider table
     * they would read as two suppliers nobody buys from, with a perfect answer
     * rate and no cost.
     */
    public function test_the_spend_table_lists_only_what_somebody_charged_for(): void
    {
        $contact = Contact::factory()->create(['email_provider' => 'Hunter']);

        EmailLookup::create([
            'contact_id' => $contact->id, 'provider_name' => 'Hunter', 'driver' => 'hunter',
            'kind' => EnrichmentProvider::KIND_FIND, 'result' => EmailLookup::RESULT_FOUND, 'cost' => 0.034,
        ]);
        EmailLookup::create([
            'contact_id' => $contact->id, 'provider_name' => 'Syntax and DNS', 'driver' => EmailLookup::DRIVER_FREE_GATE,
            'kind' => EnrichmentProvider::KIND_VERIFY, 'result' => EmailLookup::RESULT_INVALID, 'cost' => 0,
        ]);
        EmailLookup::create([
            'contact_id' => $contact->id, 'provider_name' => 'Delivery', 'driver' => EmailLookup::DRIVER_BOUNCE,
            'kind' => EnrichmentProvider::KIND_VERIFY, 'result' => EmailLookup::RESULT_INVALID, 'cost' => 0,
        ]);

        Livewire::test(WaterfallPerformance::class)
            ->assertSee('Hunter')
            ->assertDontSee('Delivery')
            // The free check is still reported, on its own line.
            ->assertSee('rejected');
    }

    /** A finder is answerable for the addresses it supplied that then bounced. */
    public function test_spend_reports_how_many_of_a_finders_addresses_bounced(): void
    {
        $contact = Contact::factory()->create(['email_provider' => 'Hunter']);

        EmailLookup::create([
            'contact_id' => $contact->id, 'provider_name' => 'Hunter', 'driver' => 'hunter',
            'kind' => EnrichmentProvider::KIND_FIND, 'result' => EmailLookup::RESULT_FOUND, 'cost' => 0.034,
        ]);
        EmailLookup::create([
            'contact_id' => $contact->id, 'provider_name' => 'Delivery', 'driver' => EmailLookup::DRIVER_BOUNCE,
            'kind' => EnrichmentProvider::KIND_VERIFY, 'result' => EmailLookup::RESULT_INVALID, 'cost' => 0,
        ]);

        $bounced = Livewire::test(WaterfallPerformance::class)->viewData('bouncedByFinder');

        $this->assertSame(1, (int) $bounced['Hunter']);
    }
}
