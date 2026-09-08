<?php

namespace Tests\Feature;

use App\Jobs\EnrichContact;
use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Models\User;
use App\Services\Enrichment\EmailWaterfall;
use App\Services\Enrichment\EnrichmentSwitch;
use App\Support\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DomainAlwaysAcceptsMail;
use Tests\Support\FakeEnrichment;
use Tests\Support\SaysValid;
use Tests\TestCase;

/**
 * The switch that stops the waterfall spending money.
 *
 * Every lead submitted over the API runs the waterfall by itself, and anything
 * holding a token can submit, so there has to be one thing to reach for when a
 * caller runs away with it.
 */
class EnrichmentSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeEnrichment::reset();
        $this->app->bind(DnsResolver::class, DomainAlwaysAcceptsMail::class);
        config(['enrichment.drivers' => ['says-valid' => SaysValid::class]]);

        EnrichmentProvider::create([
            'name' => 'says-valid',
            'driver' => 'says-valid',
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'position' => 1,
            'enabled' => true,
            'credentials' => ['api_key' => 'test-key'],
        ]);
    }

    /** A bearer token that may submit leads, made the way the API tests make one. */
    private function submit(array $payload)
    {
        $token = User::factory()->create()->createToken('test', ['write'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/contacts', $payload);
    }

    private function lead(): Contact
    {
        return Contact::create(['name' => 'Sam Carter', 'email' => 'sam@acme.com']);
    }

    public function test_it_is_on_unless_somebody_turns_it_off(): void
    {
        // Nothing stored yet. A fresh system checks emails.
        $this->assertTrue(EnrichmentSwitch::isOn());
    }

    public function test_switched_off_nothing_is_sent_to_any_provider(): void
    {
        EnrichmentSwitch::turnOff();

        app(EmailWaterfall::class)->run($contact = $this->lead());

        $this->assertSame([], FakeEnrichment::$calls, 'A provider was called with enrichment off.');
        $this->assertFalse($contact->lookups()->exists(), 'Something was billed with enrichment off.');
    }

    public function test_switched_off_a_lead_is_left_pending_rather_than_judged(): void
    {
        EnrichmentSwitch::turnOff();

        app(EmailWaterfall::class)->run($contact = $this->lead());
        $contact->refresh();

        /*
         * Pending is the one status meaning "we have not looked", so the lead
         * is still there to be checked when this goes back on. Anything settled
         * would never be looked at again.
         */
        $this->assertSame(Contact::EMAIL_PENDING, $contact->email_status);
        $this->assertFalse($contact->isEmailResolved());
        $this->assertFalse(Contact::sendable()->whereKey($contact->id)->exists());
    }

    public function test_switched_back_on_the_same_lead_is_checked_normally(): void
    {
        EnrichmentSwitch::turnOff();
        app(EmailWaterfall::class)->run($contact = $this->lead());

        EnrichmentSwitch::turnOn();
        app(EmailWaterfall::class)->run($contact->refresh());

        // Nothing was spent while it was off, and nothing was lost either.
        $this->assertSame(Contact::EMAIL_VALID, $contact->fresh()->email_status);
    }

    public function test_the_api_does_not_queue_work_that_would_do_nothing(): void
    {
        Queue::fake();
        EnrichmentSwitch::turnOff();

        $this->submit(['email' => 'sam@acme.com', 'name' => 'Sam Carter'])->assertOk();

        // The lead is still stored. Only the spending stops.
        $this->assertDatabaseHas('contacts', ['email' => 'sam@acme.com']);
        Queue::assertNotPushed(EnrichContact::class);
    }

    public function test_the_api_queues_as_usual_when_it_is_on(): void
    {
        Queue::fake();

        $this->submit(['email' => 'sam@acme.com', 'name' => 'Sam Carter'])->assertOk();

        Queue::assertPushed(EnrichContact::class);
    }

    public function test_a_job_already_queued_when_it_was_switched_off_still_stops(): void
    {
        /*
         * The switch is checked inside the waterfall as well as before the job
         * is queued. A queue holding a thousand jobs is exactly the situation
         * somebody reaches for this in, and those jobs have already been
         * dispatched.
         */
        $contact = $this->lead();
        EnrichmentSwitch::turnOff();

        (new EnrichContact($contact->id))->handle(app(EmailWaterfall::class));

        $this->assertSame([], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_PENDING, $contact->fresh()->email_status);
    }

    public function test_it_says_how_many_leads_are_waiting(): void
    {
        EnrichmentSwitch::turnOff();

        $this->lead();
        Contact::create(['name' => 'Dana Wu', 'email' => 'dana@acme.com']);
        Contact::create(['name' => 'Settled', 'email' => 'done@acme.com', 'email_status' => Contact::EMAIL_VALID]);

        // Only the ones actually stuck, so the number means something.
        $this->assertSame(2, EnrichmentSwitch::waiting());
    }
}
