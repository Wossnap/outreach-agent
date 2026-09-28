<?php

namespace Tests\Feature;

use App\Livewire\Contacts\Index;
use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_filters_by_email_and_company_combined(): void
    {
        Contact::factory()->create(['email' => 'jane@acme.com', 'company' => 'Acme']);
        Contact::factory()->create(['email' => 'john@acme.com', 'company' => 'Acme']);
        Contact::factory()->create(['email' => 'jane@other.com', 'company' => 'Other']);

        Livewire::test(Index::class)
            // Shut the panel: it starts open, and the email typeahead would
            // otherwise list jane@other.com as a suggestion above the table.
            ->call('toggleFilters')
            ->set('email', 'jane')
            ->set('company', 'Acme')
            ->assertSee('jane@acme.com')
            ->assertDontSee('john@acme.com')
            ->assertDontSee('jane@other.com');
    }

    public function test_filters_by_enrollment_status(): void
    {
        $replied = Contact::factory()->create(['email' => 'replied@x.com']);
        Enrollment::factory()->create(['contact_id' => $replied->id, 'status' => Enrollment::STATUS_STOPPED_REPLY]);
        Contact::factory()->create(['email' => 'fresh@x.com']);

        Livewire::test(Index::class)
            ->set('enrollmentStatuses', [Enrollment::STATUS_STOPPED_REPLY])
            ->assertSee('replied@x.com')
            ->assertDontSee('fresh@x.com');
    }

    public function test_filters_by_suppressed(): void
    {
        Contact::factory()->create(['email' => 'gone@x.com']);
        Suppression::suppress('gone@x.com', Suppression::REASON_UNSUBSCRIBED);
        Contact::factory()->create(['email' => 'here@x.com']);

        Livewire::test(Index::class)
            ->set('suppressed', 'yes')
            ->assertSee('gone@x.com')
            ->assertDontSee('here@x.com');

        Livewire::test(Index::class)
            ->set('suppressed', 'no')
            ->assertSee('here@x.com')
            ->assertDontSee('gone@x.com');
    }

    public function test_sorts_by_allowed_column_and_toggles_direction(): void
    {
        Contact::factory()->create(['email' => 'a@x.com']);
        Contact::factory()->create(['email' => 'z@x.com']);

        $component = Livewire::test(Index::class)->call('sortBy', 'email');
        $this->assertSame('asc', $component->get('sortDirection'));
        $component->assertSeeInOrder(['a@x.com', 'z@x.com']);

        $component->call('sortBy', 'email');
        $this->assertSame('desc', $component->get('sortDirection'));
        $component->assertSeeInOrder(['z@x.com', 'a@x.com']);
    }

    public function test_rejects_unlisted_sort_columns(): void
    {
        $component = Livewire::test(Index::class)->call('sortBy', 'custom');

        $this->assertSame('', $component->get('sortField'));
    }

    public function test_clear_filters_resets_everything(): void
    {
        $component = Livewire::test(Index::class)
            ->set('email', 'x')
            ->set('suppressed', 'yes')
            ->set('sources', ['app-1']);

        $this->assertSame(3, $component->instance()->activeFilterCount());

        $component->call('clearFilters');

        $this->assertSame(0, $component->instance()->activeFilterCount());
    }

    public function test_expanding_a_lead_shows_what_the_scraper_sent_about_them(): void
    {
        /*
         * None of this was on the screen anywhere. A job title and a profile
         * URL arrive from the scraper on every lead it finds, and the whole
         * point of the merge is that the two systems describe one person: a
         * person you cannot see is not much of a merge.
         */
        $contact = Contact::factory()->create([
            'email' => 'sam@acme.com',
            'job_title' => 'Head of Operations',
            'domain' => 'acme.com',
            'profile_url' => 'https://linkedin.com/in/sam-carter',
            'email_provider' => 'Findymail',
            'email_checked_at' => now(),
            'extra' => ['linkedin' => ['degree' => '2nd', 'last_comment' => 'Congratulations on the new role']],
        ]);

        Livewire::test(Index::class)
            ->call('toggleExpand', $contact->id)
            ->assertSee('Head of Operations')
            ->assertSee('acme.com')
            ->assertSee('linkedin.com/in/sam-carter')
            // Who supplied the address, which is the only way to know who to
            // hold answerable when it bounces.
            ->assertSee('Findymail')
            // Filed under whoever sent it, rather than one line of JSON.
            ->assertSee('Last comment')
            ->assertSee('Congratulations on the new role');
    }

    public function test_a_lead_nobody_has_looked_up_says_so_rather_than_showing_a_blank(): void
    {
        $contact = Contact::factory()->create(['email_provider' => null, 'email_checked_at' => null]);

        Livewire::test(Index::class)
            ->call('toggleExpand', $contact->id)
            ->assertSee('never checked');
    }

    public function test_what_a_provider_said_is_readable_on_the_lead(): void
    {
        $contact = Contact::factory()->create();
        EmailLookup::create([
            'contact_id' => $contact->id,
            'provider_name' => 'Reoon',
            'driver' => 'reoon',
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'result' => EmailLookup::RESULT_VALID,
            'cost' => 0.0006,
            'detail' => ['status' => 'safe', 'score' => 93],
        ]);

        // It was printed here as raw JSON, which nobody could read.
        Livewire::test(Index::class)
            ->call('toggleExpand', $contact->id)
            ->assertSee('Reoon')
            // The name is bold and the verb is not, so they are separate nodes
            // in the markup even though they read as one sentence.
            ->assertSee('said it is good')
            ->assertDontSee('said Said')
            ->assertSee('status safe, score 93')
            ->assertDontSee('{"status"');
    }

    public function test_the_filters_start_open(): void
    {
        Livewire::test(Index::class)
            ->assertSet('showFilters', true)
            ->assertSee('Include leads with no address found');
    }

    public function test_leads_with_no_address_found_are_hidden_until_asked_for(): void
    {
        Contact::factory()->create(['email' => 'found@x.com']);
        Contact::factory()->withoutAnEmail()->create(['name' => 'Nobody Found', 'email_status' => Contact::EMAIL_NOT_FOUND]);

        // Nothing can be done with them from here, and they were most of the
        // page. The switch brings them back.
        Livewire::test(Index::class)
            ->assertSee('found@x.com')
            ->assertDontSee('Nobody Found')
            ->set('includeNotFound', true)
            ->assertSee('Nobody Found');
    }

    public function test_picking_not_found_in_the_address_filter_shows_them_without_the_switch(): void
    {
        Contact::factory()->create(['email' => 'found@x.com']);
        Contact::factory()->withoutAnEmail()->create(['name' => 'Nobody Found', 'email_status' => Contact::EMAIL_NOT_FOUND]);

        Livewire::test(Index::class)
            ->set('emailStatuses', [Contact::EMAIL_NOT_FOUND])
            ->assertSee('Nobody Found')
            ->assertDontSee('found@x.com');
    }

    public function test_the_not_found_switch_only_counts_as_a_filter_when_it_is_on(): void
    {
        $this->assertSame(0, Livewire::test(Index::class)->instance()->activeFilterCount());
        $this->assertSame(1, Livewire::test(Index::class)->set('includeNotFound', true)->instance()->activeFilterCount());
    }

    public function test_filters_by_category_and_niche(): void
    {
        Contact::factory()->create(['email' => 'garden@x.com', 'category' => 'Home services', 'niche' => 'Landscaping']);
        Contact::factory()->create(['email' => 'plumb@x.com', 'category' => 'Home services', 'niche' => 'Plumbing']);
        Contact::factory()->create(['email' => 'saas@x.com', 'category' => 'Software', 'niche' => 'CRM']);

        Livewire::test(Index::class)
            ->set('categories', ['Home services'])
            ->assertSee('garden@x.com')
            ->assertSee('plumb@x.com')
            ->assertDontSee('saas@x.com')
            ->set('niches', ['Plumbing'])
            ->assertSee('plumb@x.com')
            ->assertDontSee('garden@x.com');
    }

    public function test_filters_by_role(): void
    {
        Contact::factory()->create(['email' => 'owner@x.com', 'role' => 'Owner']);
        Contact::factory()->create(['email' => 'marketer@x.com', 'role' => 'Marketing lead']);

        Livewire::test(Index::class)
            ->set('roles', ['Owner'])
            ->assertSee('owner@x.com')
            ->assertDontSee('marketer@x.com');
    }

    public function test_the_detail_toggle_sits_beside_the_checkbox_and_opens_the_detail(): void
    {
        // On a table this wide a column at the far right scrolled out of
        // view, which read as the detail having gone.
        $contact = Contact::factory()->create(['email' => 'sam@acme.com', 'job_title' => 'Head of Operations']);

        $component = Livewire::test(Index::class)->assertSee('Show detail')->assertDontSee('Head of Operations');
        $html = $component->html();
        $this->assertLessThan(strpos($html, 'sam@acme.com'), strpos($html, 'toggleExpand('.$contact->id.')'));

        $component->call('toggleExpand', $contact->id)->assertSee('Head of Operations')->assertSee('Hide detail');
    }

    public function test_the_row_shows_each_enrollment_as_its_automation_tag(): void
    {
        // The count told nobody which sequences the lead is in.
        $contact = Contact::factory()->create(['email' => 'sam@acme.com']);
        $enrollment = Enrollment::factory()->create(['contact_id' => $contact->id]);
        $enrollment->automation->update(['tag' => 'agency-intro']);

        $html = Livewire::test(Index::class)->html();

        $this->assertStringContainsString('agency-intro', $html);
        $this->assertLessThan(strpos($html, 'sam@acme.com'), strpos($html, $contact->created_at->timezone(config('outreach.timezone'))->format('j M Y')));
    }

    public function test_sorts_by_niche(): void
    {
        Contact::factory()->create(['email' => 'b@x.com', 'niche' => 'Beta']);
        Contact::factory()->create(['email' => 'a@x.com', 'niche' => 'Alpha']);

        Livewire::test(Index::class)
            ->call('sortBy', 'niche')
            ->assertSeeInOrder(['a@x.com', 'b@x.com']);
    }

    public function test_name_and_company_link_to_linkedin_when_the_urls_are_known(): void
    {
        Contact::factory()->create([
            'name' => 'Sam Carter',
            'profile_url' => 'https://linkedin.com/in/sam-carter',
            'company' => 'Acme Ltd',
            'company_url' => 'https://linkedin.com/company/acme',
        ]);
        Contact::factory()->create(['name' => 'No Link', 'profile_url' => null, 'company' => 'Linkless', 'company_url' => null]);

        Livewire::test(Index::class)
            ->assertSeeHtml('href="https://linkedin.com/in/sam-carter"')
            ->assertSeeHtml('href="https://linkedin.com/company/acme"')
            ->assertSee('No Link')
            ->assertSee('Linkless');
    }

    public function test_manual_suppress_stops_active_enrollments(): void
    {
        $contact = Contact::factory()->create(['email' => 'target@x.com']);
        $enrollment = Enrollment::factory()->create(['contact_id' => $contact->id]);

        Livewire::test(Index::class)->call('suppress', $contact->id);

        $this->assertTrue(Suppression::isSuppressed('target@x.com'));
        $this->assertSame(Enrollment::STATUS_STOPPED_SUPPRESSED, $enrollment->fresh()->status);
    }
}
