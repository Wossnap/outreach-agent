<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule that decides whether two pieces of data describe one person.
 *
 * Leads arrive over the API with an email and no LinkedIn profile, and from the
 * scraper with a profile and no email, so the rule cannot be a unique index on
 * either one. It lives on the model, and every path has to agree on it.
 */
class ContactIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_identifies_the_person_before_the_profile_url(): void
    {
        $existing = Contact::create([
            'email' => 'sam@acme.com',
            'profile_url' => 'https://linkedin.com/in/sam-old',
        ]);

        $found = Contact::findOrNewFor('sam@acme.com', 'https://linkedin.com/in/sam-new');

        $this->assertTrue($found->exists);
        $this->assertSame($existing->id, $found->id);
    }

    public function test_falls_back_to_the_profile_url_when_there_is_no_email(): void
    {
        $existing = Contact::create(['profile_url' => 'https://linkedin.com/in/sam']);

        $found = Contact::findOrNewFor(null, 'https://linkedin.com/in/sam');

        $this->assertTrue($found->exists);
        $this->assertSame($existing->id, $found->id);
    }

    public function test_a_lead_with_an_email_and_no_profile_url_can_be_stored(): void
    {
        // A unique, non-null profile_url would shut out every source that is
        // not LinkedIn, which is most of them.
        $contact = Contact::findOrNewFor('sam@acme.com', null);
        $contact->save();

        $this->assertTrue($contact->exists);
        $this->assertNull($contact->profile_url);
        $this->assertSame('sam@acme.com', $contact->email);
    }

    public function test_returns_an_unsaved_lead_when_nobody_matches(): void
    {
        $contact = Contact::findOrNewFor('nobody@acme.com', 'https://linkedin.com/in/nobody');

        $this->assertFalse($contact->exists);
        $this->assertSame('nobody@acme.com', $contact->email);
        $this->assertSame('https://linkedin.com/in/nobody', $contact->profile_url);
        $this->assertSame(0, Contact::count());
    }

    public function test_the_same_person_written_differently_is_one_lead(): void
    {
        Contact::create(['email' => '  Sam@ACME.com ']);

        $found = Contact::findOrNewFor('sam@acme.com', null);

        $this->assertTrue($found->exists);
        $this->assertSame(1, Contact::count());
    }

    public function test_the_domain_is_kept_in_step_with_the_email(): void
    {
        $contact = Contact::create(['email' => 'Sam@Acme.com']);

        $this->assertSame('acme.com', $contact->domain);

        $contact->update(['email' => 'sam@newplace.io']);

        $this->assertSame('newplace.io', $contact->fresh()->domain);
    }

    public function test_a_new_lead_is_not_sendable_until_it_has_been_checked(): void
    {
        $contact = Contact::create(['email' => 'sam@acme.com']);

        $this->assertSame(Contact::EMAIL_PENDING, $contact->email_status);
    }

    public function test_one_address_can_only_ever_belong_to_one_lead(): void
    {
        // Two rows for one person is the ordinary state of things for a while:
        // the API knows an address, the scraper knows a URL, and until an
        // address is found for the scraped row nothing connects them.
        Contact::create(['email' => 'sam@acme.com', 'source' => Contact::SOURCE_API]);
        $scraped = Contact::create(['profile_url' => 'https://linkedin.com/in/sam', 'source' => Contact::SOURCE_LINKEDIN]);

        $this->assertSame(2, Contact::count());

        // The moment the waterfall finds the same address for the scraped row,
        // the database refuses it rather than letting one person become two
        // sendable records. Handling that collision is the waterfall's job, and
        // this is the guarantee it gets to rely on.
        $this->expectException(UniqueConstraintViolationException::class);

        $scraped->update(['email' => 'sam@acme.com']);
    }

    /**
     * One LinkedIn profile is one lead, however the URL was written down.
     *
     * The URL is the identity for everybody who arrives without an address, so
     * every spelling of it has to settle to the same thing. It does not arrive
     * in one form: the scraper reads it off a card, a finder returns its own
     * version, and anything pasted from a browser brings tracking parameters
     * with it. Stored as sent, those were three different people.
     */
    public function test_one_profile_written_several_ways_is_one_lead(): void
    {
        $spellings = [
            'https://www.linkedin.com/in/sam-carter',
            'https://linkedin.com/in/sam-carter/',
            'http://www.linkedin.com/in/sam-carter?trk=public_post',
            'https://www.linkedin.com/in/Sam-Carter',
        ];

        foreach ($spellings as $url) {
            $contact = Contact::findOrNewFor(null, $url, 'Sam Carter');
            $contact->save();
        }

        $this->assertSame(1, Contact::count());
        $this->assertSame('https://linkedin.com/in/sam-carter', Contact::sole()->profile_url);
    }

    public function test_a_profile_url_is_tidied_whoever_writes_it(): void
    {
        // Normalising only inside findOrNewFor was not enough: the ingest
        // service assigns whatever the caller sent straight over the top, and
        // the waterfall does the same with a URL a finder handed back.
        $contact = Contact::create(['name' => 'Sam']);
        $contact->update(['profile_url' => 'https://www.linkedin.com/in/sam-carter/?utm_source=x']);

        $this->assertSame('https://linkedin.com/in/sam-carter', $contact->fresh()->profile_url);
    }

    public function test_a_domain_the_caller_states_beats_the_one_in_the_address(): void
    {
        // Somebody at Acme reachable on a personal address works at Acme. The
        // address always won, so they were recorded as working at gmail.com,
        // which then went to the finders as the company to search and to the
        // drafter as the company to write about.
        $token = User::factory()->create()->createToken('t', ['write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/contacts', [
                'email' => 'sam@gmail.com',
                'name' => 'Sam Carter',
                'domain' => 'acme.com',
            ])->assertOk();

        $this->assertSame('acme.com', Contact::sole()->domain);
    }

    public function test_the_address_still_supplies_a_domain_when_nobody_states_one(): void
    {
        $token = User::factory()->create()->createToken('t', ['write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/contacts', ['email' => 'sam@acme.com', 'name' => 'Sam Carter'])
            ->assertOk();

        $this->assertSame('acme.com', Contact::sole()->domain);
    }
}
