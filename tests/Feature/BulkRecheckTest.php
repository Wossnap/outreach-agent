<?php

namespace Tests\Feature;

use App\Jobs\EnrichContact;
use App\Livewire\Contacts\Index as LeadsIndex;
use App\Models\Contact;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Enrichment\EnrichmentSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sending a selection of leads back through the waterfall.
 *
 * The reason a selection exists on that page at all: after adding a provider or
 * giving one a key, every lead already written off deserves another go, and
 * doing that one row at a time is not something anybody finishes.
 *
 * Every lead sent back costs money at every provider it reaches, so the rules
 * about who is skipped matter more than the mechanics.
 */
class BulkRecheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
    }

    public function test_everything_ticked_goes_back_through_the_waterfall(): void
    {
        $leads = Contact::factory()->count(3)->create(['email_status' => Contact::EMAIL_NOT_FOUND]);

        Livewire::test(LeadsIndex::class)
            ->set('selected', $leads->pluck('id')->map(strval(...))->all())
            ->call('recheckSelected')
            ->assertSee('3 lead(s) queued');

        Queue::assertPushed(EnrichContact::class, 3);

        // Back to pending, so the screen stops claiming a verdict that is being
        // reconsidered.
        foreach ($leads as $lead) {
            $this->assertSame(Contact::EMAIL_PENDING, $lead->fresh()->email_status);
            $this->assertNull($lead->fresh()->email_checked_at);
        }
    }

    public function test_the_selection_is_emptied_afterwards(): void
    {
        $lead = Contact::factory()->create();

        Livewire::test(LeadsIndex::class)
            ->set('selected', [(string) $lead->id])
            ->call('recheckSelected')
            // Otherwise pressing it twice in a row silently spends twice.
            ->assertSet('selected', []);
    }

    public function test_somebody_who_opted_out_is_skipped_and_said_so(): void
    {
        $wanted = Contact::factory()->create();
        $optedOut = Contact::factory()->create();
        Suppression::suppress($optedOut->email, Suppression::REASON_MANUAL);

        Livewire::test(LeadsIndex::class)
            ->set('selected', [(string) $wanted->id, (string) $optedOut->id])
            ->call('recheckSelected')
            ->assertSee('1 lead(s) queued')
            // Not quietly dropped: somebody who ticked fifty and got
            // forty-eight has a right to know which two and why.
            ->assertSee('1 skipped');

        Queue::assertPushed(EnrichContact::class, 1);
    }

    public function test_nothing_is_spent_while_enrichment_is_switched_off(): void
    {
        EnrichmentSwitch::turnOff();
        $lead = Contact::factory()->create();

        Livewire::test(LeadsIndex::class)
            ->set('selected', [(string) $lead->id])
            ->call('recheckSelected')
            ->assertSee('Enrichment is switched off');

        Queue::assertNothingPushed();
        $this->assertNotSame(Contact::EMAIL_PENDING, $lead->fresh()->email_status ?? Contact::EMAIL_PENDING);
    }

    public function test_pressing_it_with_nothing_ticked_says_so(): void
    {
        Livewire::test(LeadsIndex::class)
            ->call('recheckSelected')
            ->assertSee('Nothing was selected');

        Queue::assertNothingPushed();
    }

    public function test_the_header_tick_selects_the_page_and_clears_it_again(): void
    {
        $leads = Contact::factory()->count(2)->create();
        $ids = $leads->pluck('id')->map(strval(...))->all();

        $component = Livewire::test(LeadsIndex::class)
            ->call('toggleSelectPage', $ids)
            ->assertSet('selected', $ids);

        $component->call('toggleSelectPage', $ids)->assertSet('selected', []);
    }

    public function test_the_header_tick_leaves_leads_on_other_pages_alone(): void
    {
        /*
         * The page rather than the whole filtered set, on purpose. A tick box
         * that quietly selects four thousand leads behind the ones on screen is
         * how somebody spends a great deal of money by accident.
         */
        $onScreen = Contact::factory()->count(2)->create();
        $elsewhere = Contact::factory()->create();

        Livewire::test(LeadsIndex::class)
            ->call('toggleSelectPage', $onScreen->pluck('id')->all())
            ->assertSet('selected', $onScreen->pluck('id')->map(strval(...))->all());

        $this->assertDatabaseHas('contacts', ['id' => $elsewhere->id]);
    }

    public function test_one_lead_still_gets_a_message_naming_them(): void
    {
        $lead = Contact::factory()->create(['email' => 'ruth@example.com']);

        // The single-lead button can name the person; the bulk one cannot,
        // which is the only reason there are two messages.
        Livewire::test(LeadsIndex::class)
            ->call('recheck', $lead->id)
            ->assertSee('ruth@example.com is queued');
    }
}
