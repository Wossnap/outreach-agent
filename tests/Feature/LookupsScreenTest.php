<?php

namespace Tests\Feature;

use App\Livewire\Settings\Lookups;
use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The calls behind the totals.
 *
 * Spend says a provider answered four times in ten. This page is where that
 * becomes an argument that can be had: which leads, what was said about them,
 * and in whose words.
 */
class LookupsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function lookup(array $attributes = []): EmailLookup
    {
        return EmailLookup::create(array_merge([
            'contact_id' => Contact::factory()->create()->id,
            'provider_name' => 'Reoon',
            'driver' => 'reoon',
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'result' => EmailLookup::RESULT_VALID,
            'cost' => 0.0006,
            'duration_ms' => 412,
        ], $attributes));
    }

    public function test_the_page_renders(): void
    {
        $this->get('/settings/lookups')->assertOk();
    }

    public function test_it_is_behind_the_login(): void
    {
        auth()->logout();

        $this->get('/settings/lookups')->assertRedirect('/login');
    }

    public function test_every_call_is_listed_including_the_ones_that_found_nothing(): void
    {
        $this->lookup(['provider_name' => 'Hunter', 'driver' => 'hunter', 'kind' => EnrichmentProvider::KIND_FIND, 'result' => EmailLookup::RESULT_NOTHING, 'cost' => 0]);
        $this->lookup();

        // A chain judged only on the calls that worked looks perfect and says
        // nothing, so a miss has to be as visible as a hit.
        Livewire::test(Lookups::class)
            ->assertSee('Hunter')
            ->assertSee('Had nothing')
            ->assertSee('Reoon')
            ->assertSee('It is good');
    }

    public function test_the_calls_can_be_read_filtered_by_provider(): void
    {
        $this->lookup(['provider_name' => 'Hunter', 'driver' => 'hunter']);
        $this->lookup(['provider_name' => 'Reoon']);

        // The link Spend puts on every provider row.
        Livewire::test(Lookups::class, ['providers' => ['Reoon']])
            ->assertSee('Reoon')
            ->assertDontSee('Hunter');
    }

    public function test_the_calls_can_be_filtered_by_what_was_said(): void
    {
        $this->lookup(['result' => EmailLookup::RESULT_INVALID]);
        $this->lookup(['result' => EmailLookup::RESULT_VALID]);

        Livewire::test(Lookups::class)
            ->set('results', [EmailLookup::RESULT_INVALID])
            ->assertSee('It is dead')
            ->assertDontSee('It is good');
    }

    public function test_the_calls_can_be_filtered_by_step(): void
    {
        $this->lookup(['provider_name' => 'Hunter', 'driver' => 'hunter', 'kind' => EnrichmentProvider::KIND_FIND, 'result' => EmailLookup::RESULT_FOUND]);
        $this->lookup();

        Livewire::test(Lookups::class)
            ->set('kind', EnrichmentProvider::KIND_FIND)
            ->assertSee('Hunter')
            ->assertDontSee('Reoon');
    }

    public function test_a_lead_can_be_found_by_name_when_there_is_no_address_yet(): void
    {
        /*
         * The case that makes searching by address alone useless here: a finder
         * is called precisely because there is no address, so the call that
         * produced one cannot be found by the address it produced.
         */
        $nameless = Contact::factory()->create(['email' => null, 'name' => 'Tom Adeyemi']);
        $this->lookup(['contact_id' => $nameless->id, 'provider_name' => 'Findymail', 'driver' => 'findymail']);
        $this->lookup(['provider_name' => 'Reoon']);

        Livewire::test(Lookups::class)
            ->set('lead', 'adeyemi')
            ->assertSee('Findymail')
            ->assertDontSee('Reoon');
    }

    public function test_what_the_provider_said_is_readable_rather_than_json(): void
    {
        $this->lookup(['detail' => ['status' => 'safe', 'score' => 93, 'safe to send' => true]]);

        // It was printed as raw JSON on the lead's history, where nobody could
        // read it. Kept exactly as it arrived, presented as a sentence.
        Livewire::test(Lookups::class)->assertSee('status safe, score 93, safe to send yes');
    }

    public function test_a_provider_that_gave_a_sentence_is_quoted_rather_than_taken_apart(): void
    {
        $this->lookup([
            'provider_name' => 'Syntax and DNS',
            'driver' => EmailLookup::DRIVER_FREE_GATE,
            'result' => EmailLookup::RESULT_INVALID,
            'cost' => 0,
            'detail' => ['reason' => 'The domain publishes nowhere to deliver mail.'],
        ]);

        Livewire::test(Lookups::class)->assertSee('The domain publishes nowhere to deliver mail.');
    }

    public function test_a_verdict_reads_as_a_sentence_with_the_provider_in_front(): void
    {
        /*
         * "BounceBan said Said it is good" is what gluing a name to a label
         * produced, and "Findymail said Found an address" is not English
         * either. Only some verdicts are things a provider said: a finder that
         * returned an address did not say anything, it found something.
         */
        $verifier = $this->lookup(['provider_name' => 'BounceBan', 'driver' => 'bounceban']);
        $finder = $this->lookup([
            'provider_name' => 'Findymail',
            'driver' => 'findymail',
            'kind' => EnrichmentProvider::KIND_FIND,
            'result' => EmailLookup::RESULT_FOUND,
        ]);
        $missed = $this->lookup([
            'provider_name' => 'Hunter',
            'driver' => 'hunter',
            'kind' => EnrichmentProvider::KIND_FIND,
            'result' => EmailLookup::RESULT_NOTHING,
        ]);

        $this->assertSame('BounceBan said it is good', $verifier->sentence());
        $this->assertSame('Findymail found an address', $finder->sentence());
        $this->assertSame('Hunter had nothing', $missed->sentence());
    }

    public function test_the_standalone_label_never_repeats_the_column_it_sits_under(): void
    {
        // The column and the filter are both headed "Said".
        foreach (EmailLookup::results() as $label) {
            $this->assertStringStartsNotWith('Said', $label);
        }
    }

    public function test_one_call_can_be_opened_in_full(): void
    {
        $lookup = $this->lookup(['detail' => ['status' => 'safe', 'score' => 93]]);

        Livewire::test(Lookups::class)
            ->call('toggleExpand', $lookup->id)
            ->assertSee('What the provider said')
            ->assertSee('Status')
            ->assertSee('Score');
    }

    public function test_a_call_with_no_detail_says_so_rather_than_showing_a_blank(): void
    {
        $lookup = $this->lookup(['result' => EmailLookup::RESULT_NOTHING, 'detail' => null]);

        Livewire::test(Lookups::class)
            ->call('toggleExpand', $lookup->id)
            ->assertSee('Nothing was recorded for this call');
    }

    public function test_the_free_work_and_a_bounce_are_listed_here_even_though_spend_leaves_them_out(): void
    {
        $this->lookup([
            'provider_name' => 'Delivery',
            'driver' => EmailLookup::DRIVER_BOUNCE,
            'result' => EmailLookup::RESULT_INVALID,
            'cost' => 0,
            'detail' => ['reason' => 'The message bounced, so this address does not accept mail.'],
        ]);

        /*
         * Deliberately different from the Spend page, which excludes both. This
         * is the lead's history, where a bounce is one of the most important
         * things that ever happened to an address; Spend is a table of what
         * each provider costs, and nobody sells us a bounce.
         */
        Livewire::test(Lookups::class)->assertSee('Delivery');
    }

    public function test_deleting_a_lead_takes_its_calls_with_it(): void
    {
        $contact = Contact::factory()->create();
        $this->lookup(['contact_id' => $contact->id]);

        $contact->delete();

        /*
         * Pinned because it is surprising and it is not this page's decision:
         * the foreign key cascades, so removing a lead also removes the record
         * of what was spent looking them up, and the Spend totals fall by that
         * much. Worth knowing before deleting anybody.
         */
        $this->assertSame(0, EmailLookup::query()->count());

        Livewire::test(Lookups::class)->assertSee('Nothing has been looked up yet');
    }
}
