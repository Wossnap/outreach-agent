<?php

namespace Tests\Feature;

use App\Jobs\EnrichContact;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Submitting a person whose address we do not have.
 *
 * Which is the case the finding half of the waterfall exists for. A source that
 * already had the address would have no reason to ask us to find it.
 *
 * This was refused: a lead had to arrive with an email or a profile, so the
 * only way to reach the finders was a scraped profile, and nothing submitted
 * over the API could ever be looked up.
 */
class SubmitSomebodyWithNoAddressTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $payload)
    {
        $token = User::factory()->create()->createToken('test', ['write'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/contacts', $payload);
    }

    public function test_a_name_and_a_company_domain_is_enough(): void
    {
        $this->submit(['name' => 'Sam Carter', 'company' => 'Acme', 'domain' => 'acme.com'])
            ->assertOk()
            ->assertJsonPath('data.contacts.0.created', true);

        $contact = Contact::sole();

        $this->assertSame('Sam Carter', $contact->name);
        $this->assertSame('acme.com', $contact->domain);
        $this->assertNull($contact->email);
    }

    public function test_a_full_url_serves_as_the_domain(): void
    {
        // What a source is likelier to hold than a bare domain, sent in the
        // same field. There is no second "website" field to choose between:
        // one fact, one name, and the host is taken from whatever arrives.
        $this->submit(['name' => 'Sam Carter', 'domain' => 'https://www.acme.com/about'])->assertOk();

        $this->assertSame('acme.com', Contact::sole()->domain);
    }

    public function test_the_finders_are_actually_asked(): void
    {
        Queue::fake();

        $this->submit(['name' => 'Sam Carter', 'domain' => 'acme.com'])->assertOk();

        // The point of the whole thing. Without this the lead is stored and
        // nothing ever tries to find an address for it.
        Queue::assertPushed(EnrichContact::class);
    }

    public function test_the_same_person_sent_twice_is_one_record(): void
    {
        $this->submit(['name' => 'Sam Carter', 'domain' => 'acme.com'])->assertOk();

        $this->submit(['name' => 'Sam Carter', 'domain' => 'acme.com', 'job_title' => 'Head of Growth'])
            ->assertOk()
            ->assertJsonPath('data.contacts.0.created', false);

        // A source that resends its list must not double it every time.
        $this->assertSame(1, Contact::count());
        $this->assertSame('Head of Growth', Contact::sole()->job_title);
    }

    public function test_the_spelling_of_the_name_does_not_split_the_record(): void
    {
        $this->submit(['name' => 'Sam Carter', 'domain' => 'acme.com'])->assertOk();
        $this->submit(['name' => 'sam carter', 'domain' => 'acme.com'])->assertOk();

        $this->assertSame(1, Contact::count());
    }

    public function test_the_same_name_at_a_different_company_is_a_different_person(): void
    {
        $this->submit(['name' => 'Sam Carter', 'domain' => 'acme.com'])->assertOk();
        $this->submit(['name' => 'Sam Carter', 'domain' => 'globex.com'])->assertOk();

        $this->assertSame(2, Contact::count());
    }

    public function test_a_name_with_no_domain_is_still_refused(): void
    {
        /*
         * Nothing to find an address with, and nothing to tell this person
         * apart from anyone else of the same name. A finder searches by domain,
         * so a name on its own cannot be looked up by anybody.
         */
        $this->submit(['name' => 'Sam Carter', 'company' => 'Acme'])
            ->assertStatus(422);

        $this->assertSame(0, Contact::count());
    }

    public function test_an_empty_submission_is_still_refused(): void
    {
        $this->submit(['source' => 'grok'])->assertStatus(422);

        $this->assertSame(0, Contact::count());
    }
}
