<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A contact describes a person, whatever we happen to know about them.
 *
 * One record carries an address, or a LinkedIn profile, or a name at a company
 * and neither. What is asserted here is that each of those is enough on its
 * own, and that they do not tread on each other.
 */
class ContactShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scraper_columns_are_gone(): void
    {
        /*
         * times_seen counted engagements, which is what counting engagements
         * gives. first_seen_at and last_seen_at recorded when the scraper saw
         * somebody, which is what created_at and updated_at say for every other
         * source. degree is LinkedIn's alone.
         */
        foreach (['degree', 'times_seen', 'first_seen_at', 'last_seen_at', 'headline'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('contacts', $column),
                "The contact still carries [{$column}].",
            );
        }
    }

    public function test_the_columns_that_had_a_better_counterpart_are_gone(): void
    {
        // website lost to domain, which is what a finder actually searches by,
        // and custom lost to extra, which records who said what.
        foreach (['website', 'custom'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('contacts', $column),
                "The contact still carries [{$column}].",
            );
        }
    }

    public function test_it_carries_what_every_source_has(): void
    {
        foreach (['email', 'name', 'job_title', 'company', 'category', 'niche', 'company_url', 'domain', 'profile_url', 'source', 'extra'] as $column) {
            $this->assertTrue(Schema::hasColumn('contacts', $column), "The lead is missing [{$column}].");
        }
    }

    public function test_somebody_can_be_stored_with_no_address_at_all(): void
    {
        /*
         * The people this system exists to find an address for are, by
         * definition, people it has no address for.
         */
        $contact = Contact::create([
            'profile_url' => 'https://www.linkedin.com/in/sam-carter',
            'name' => 'Sam Carter',
        ]);

        $this->assertNull($contact->fresh()->email);
        $this->assertSame(Contact::EMAIL_PENDING, $contact->fresh()->email_status);
        $this->assertFalse($contact->isSendable());
    }

    public function test_several_people_can_be_waiting_on_an_address_at_once(): void
    {
        // A unique index on a nullable column allows many nulls, which is what
        // makes "we do not know yet" storable more than once.
        Contact::create(['profile_url' => 'https://www.linkedin.com/in/one', 'name' => 'One']);
        Contact::create(['profile_url' => 'https://www.linkedin.com/in/two', 'name' => 'Two']);

        $this->assertSame(2, Contact::query()->whereNull('email')->count());
    }

    public function test_a_source_can_keep_what_we_have_no_column_for(): void
    {
        $contact = Contact::create(['email' => 'sam@acme.com']);

        $contact->rememberExtra('linkedin', ['score' => 94, 'twitter' => '@sam']);
        $contact->save();

        $this->assertSame(94, $contact->fresh()->extraFrom('linkedin', 'score'));
        $this->assertSame('@sam', $contact->fresh()->extraFrom('linkedin', 'twitter'));
    }

    public function test_two_sources_can_say_the_same_word_and_mean_different_things(): void
    {
        // Namespacing is the point. One source's score is a connection
        // strength and another's is a confidence in a guess.
        $contact = Contact::create(['email' => 'sam@acme.com']);

        $contact->rememberExtra('linkedin', ['score' => 94]);
        $contact->rememberExtra('tube-trend-tool', ['score' => 12]);
        $contact->save();

        $this->assertSame(94, $contact->fresh()->extraFrom('linkedin', 'score'));
        $this->assertSame(12, $contact->fresh()->extraFrom('tube-trend-tool', 'score'));
    }

    public function test_one_source_does_not_trample_another(): void
    {
        $contact = Contact::create(['email' => 'sam@acme.com']);

        $contact->rememberExtra('linkedin', ['score' => 94]);
        $contact->rememberExtra('linkedin', ['twitter' => '@sam']);
        $contact->save();

        $this->assertSame(
            ['score' => 94, 'twitter' => '@sam'],
            $contact->fresh()->extra['linkedin'],
        );
    }

    public function test_empty_values_are_not_kept(): void
    {
        // Providers return plenty of nulls. Storing them fills the bag with
        // nothing and makes it harder to read.
        $contact = Contact::create(['email' => 'sam@acme.com']);

        $contact->rememberExtra('linkedin', ['score' => null, 'twitter' => '']);
        $contact->save();

        $this->assertNull($contact->fresh()->extra);
    }

    public function test_asking_for_something_nobody_said_gives_nothing(): void
    {
        $contact = Contact::create(['email' => 'sam@acme.com']);

        $this->assertNull($contact->extraFrom('linkedin', 'score'));
        $this->assertSame('none', $contact->extraFrom('linkedin', 'score', 'none'));
    }
}
