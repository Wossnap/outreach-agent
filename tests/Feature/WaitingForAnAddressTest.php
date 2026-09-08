<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Livewire\Settings\Waterfall;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Inbound\InboundEmail;
use App\Services\Inbound\InboundProcessor;
use App\Services\Sending\EnrollmentActivator;
use App\Support\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\DomainAlwaysAcceptsMail;
use Tests\Support\FakeEnrichment;
use Tests\Support\SaysInvalid;
use Tests\Support\SaysValid;
use Tests\TestCase;

/**
 * An enrollment made before there is anywhere to send it.
 *
 * A contact can arrive with no address, or with one nothing has checked, so
 * drafting the moment they are enrolled is not always possible. The enrollment
 * is made anyway and waits. What is tested here is that it waits, that
 * confirming the address releases it, and that nothing else does.
 */
class WaitingForAnAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeEnrichment::reset();
        $this->app->bind(DnsResolver::class, DomainAlwaysAcceptsMail::class);

        config(['enrichment.drivers' => [
            'says-valid' => SaysValid::class,
            'says-invalid' => SaysInvalid::class,
        ]]);
    }

    private function automationWithAStep(): Automation
    {
        $automation = Automation::factory()->create(['active' => true]);

        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 1]);

        return $automation;
    }

    private function push(array $payload)
    {
        $token = User::factory()->create()->createToken('test', ['write'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/contacts', $payload);
    }

    private function verifier(string $driver): void
    {
        EnrichmentProvider::create([
            'name' => $driver,
            'driver' => $driver,
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'position' => 1,
            'enabled' => true,
            'credentials' => ['api_key' => 'test-key'],
        ]);
    }

    public function test_somebody_with_no_address_is_enrolled_but_not_started(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'job-change']);

        $this->push([
            'profile_url' => 'https://www.linkedin.com/in/sam-carter',
            'name' => 'Sam Carter',
            'source' => 'linkedin',
            'tags' => ['job-change'],
        ])->assertOk()->assertJsonPath('data.contacts.0.enrollments.0.status', Enrollment::STATUS_WAITING_EMAIL);

        $enrollment = Enrollment::sole();

        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $enrollment->status);
        // No mailbox either: the choice balances load on the day of sending,
        // and one made now could be days stale by the time it is used.
        $this->assertNull($enrollment->mailbox_id);
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_confirming_the_address_starts_what_was_waiting(): void
    {
        // The waterfall runs for real; only the drafting is held back, so what
        // is asserted is that the two halves actually meet.
        Queue::fake([DraftEmailJob::class]);
        Mailbox::factory()->connected()->create();
        Automation::factory()->create(['tag' => 'job-change']);
        $this->verifier('says-valid');

        $this->push([
            'email' => 'sam@acme.com',
            'name' => 'Sam Carter',
            'tags' => ['job-change'],
        ])->assertOk();

        $contact = Contact::sole();
        $enrollment = Enrollment::sole();

        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
        $this->assertSame(Enrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertNotNull($enrollment->mailbox_id);
        Queue::assertPushed(DraftEmailJob::class, fn ($job) => $job->enrollmentId === $enrollment->id && $job->stepPosition === 1);
    }

    public function test_an_address_nobody_will_confirm_leaves_it_waiting(): void
    {
        Queue::fake([DraftEmailJob::class]);
        Mailbox::factory()->connected()->create();
        Automation::factory()->create(['tag' => 'job-change']);
        $this->verifier('says-invalid');

        $this->push([
            'email' => 'sam@acme.com',
            'name' => 'Sam Carter',
            'tags' => ['job-change'],
        ])->assertOk();

        $this->assertSame(Contact::EMAIL_INVALID, Contact::sole()->email_status);
        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, Enrollment::sole()->status);
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_being_pushed_twice_while_waiting_enrolls_once(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'job-change']);

        $payload = [
            'profile_url' => 'https://www.linkedin.com/in/sam-carter',
            'name' => 'Sam Carter',
            'tags' => ['job-change'],
        ];

        $this->push($payload)->assertOk();
        $this->push($payload)->assertOk()->assertJsonPath('data.contacts.0.enrollments.0.skipped', 'already_active');

        // Otherwise the sequence would be sent twice the moment the address
        // came back, which is the failure the caller cannot see coming.
        $this->assertSame(1, Enrollment::query()->count());
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_somebody_suppressed_is_never_started_however_good_the_address(): void
    {
        Queue::fake();
        $automation = Automation::factory()->create(['tag' => 'job-change']);
        Mailbox::factory()->connected()->create();

        $contact = Contact::factory()->pending()->create(['email' => 'sam@acme.com']);
        app(EnrollmentActivator::class)->enroll($contact, $automation);

        Suppression::suppress('sam@acme.com', Suppression::REASON_UNSUBSCRIBED);
        $contact->update(['email_status' => Contact::EMAIL_VALID]);

        $started = app(EnrollmentActivator::class)->activateWaiting($contact->fresh());

        $this->assertSame(0, $started);
        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, Enrollment::sole()->status);
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_a_bad_row_rejects_the_whole_batch(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'job-change']);

        $this->push(['contacts' => [
            ['email' => 'good@acme.com', 'tags' => ['job-change']],
            ['name' => 'No way to reach this person'],
        ]])->assertStatus(422);

        // A partial import is the worst outcome: the caller cannot tell what
        // landed, and re-sending is the only remedy.
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_a_batch_lands_as_one(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'job-change']);

        $this->push(['contacts' => [
            ['email' => 'one@acme.com', 'tags' => ['job-change']],
            ['profile_url' => 'https://www.linkedin.com/in/two', 'name' => 'Two'],
            ['name' => 'Three', 'domain' => 'globex.com'],
        ]])->assertOk()->assertJsonPath('data.created', 3);

        $this->assertSame(3, Contact::query()->count());
    }

    public function test_a_bounce_tells_the_waterfall_its_verdict_was_wrong(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'sam@acme.com',
            'email_status' => Contact::EMAIL_VALID,
            'email_provider' => 'Hunter',
        ]);

        app(InboundProcessor::class)->process(
            Mailbox::factory()->connected()->create(),
            new InboundEmail(
                gmailMessageId: 'bounce-1',
                gmailThreadId: null,
                fromEmail: 'mailer-daemon@googlemail.com',
                subject: 'Delivery Status Notification (Failure)',
                snippet: 'failed',
                bodyText: "Final-Recipient: rfc822; sam@acme.com\nAction: failed",
                headers: [],
                labelIds: ['INBOX'],
            ),
        );

        // The only true measure of whether a "valid" verdict was right.
        $this->assertSame(Contact::EMAIL_INVALID, $contact->fresh()->email_status);

        $lookup = EmailLookup::query()->where('contact_id', $contact->id)->sole();
        $this->assertSame('bounce', $lookup->driver);
        $this->assertSame(EmailLookup::RESULT_INVALID, $lookup->result);
        $this->assertSame('Hunter', $lookup->detail['found_by']);
        $this->assertSame(Contact::EMAIL_VALID, $lookup->detail['said_before_sending']);
    }

    public function test_changing_the_rule_starts_nobody_on_its_own(): void
    {
        Queue::fake();

        $automation = $this->automationWithAStep();

        $held = Contact::factory()->create([
            'email' => 'unchecked@acme.example',
            'email_status' => Contact::EMAIL_PENDING,
        ]);

        $enrollment = app(EnrollmentActivator::class)->enroll($held, $automation);

        Livewire::actingAs(User::factory()->create())
            ->test(Waterfall::class)
            ->call('toggleVerifiedEmail')
            ->assertSee('Anybody already waiting stays waiting until you start them.');

        // The rule changed. Nothing was sent to anybody because of it.
        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $enrollment->fresh()->status);
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_starting_the_backlog_is_a_separate_decision(): void
    {
        Queue::fake();

        $automation = $this->automationWithAStep();

        // Held back only by the switch: there is an address, nothing has
        // confirmed it.
        $held = Contact::factory()->create([
            'email' => 'unchecked@acme.example',
            'email_status' => Contact::EMAIL_PENDING,
        ]);

        // Nowhere to send at all, switch or no switch.
        $noAddress = Contact::factory()->create([
            'email' => null,
            'email_status' => Contact::EMAIL_PENDING,
        ]);

        $activator = app(EnrollmentActivator::class);
        $heldEnrollment = $activator->enroll($held, $automation);
        $noAddressEnrollment = $activator->enroll($noAddress, $automation);

        Livewire::actingAs(User::factory()->create())
            ->test(Waterfall::class)
            ->call('toggleVerifiedEmail')
            ->call('startEveryoneWaiting')
            ->assertSee('1 enrollment(s) have started.');

        $this->assertSame(Enrollment::STATUS_ACTIVE, $heldEnrollment->fresh()->status);
        Queue::assertPushed(DraftEmailJob::class);

        // Still waiting, and correctly so: the switch was never the reason.
        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $noAddressEnrollment->fresh()->status);
    }

    public function test_somebody_who_opted_out_is_not_started_by_the_switch(): void
    {
        Queue::fake();

        $automation = $this->automationWithAStep();

        $contact = Contact::factory()->create([
            'email' => 'opted.out@acme.example',
            'email_status' => Contact::EMAIL_PENDING,
        ]);

        $enrollment = app(EnrollmentActivator::class)->enroll($contact, $automation);
        Suppression::suppress($contact->email, Suppression::REASON_MANUAL);

        Livewire::actingAs(User::factory()->create())
            ->test(Waterfall::class)
            ->call('toggleVerifiedEmail')
            ->call('startEveryoneWaiting');

        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $enrollment->fresh()->status);
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_the_backlog_cannot_be_started_while_the_rule_still_applies(): void
    {
        Queue::fake();

        $automation = $this->automationWithAStep();

        $enrollment = app(EnrollmentActivator::class)->enroll(
            Contact::factory()->create(['email' => 'unchecked@acme.example', 'email_status' => Contact::EMAIL_PENDING]),
            $automation,
        );

        Livewire::actingAs(User::factory()->create())
            ->test(Waterfall::class)
            ->call('startEveryoneWaiting')
            ->assertSee('A confirmed address is still required');

        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $enrollment->fresh()->status);
        Queue::assertNotPushed(DraftEmailJob::class);
    }

    public function test_the_page_offers_to_start_the_backlog_with_its_number(): void
    {
        $automation = $this->automationWithAStep();
        $activator = app(EnrollmentActivator::class);

        // Waiting only on the rule: counted, and warned about.
        $activator->enroll(
            Contact::factory()->create(['email' => 'unchecked@acme.example', 'email_status' => Contact::EMAIL_PENDING]),
            $automation,
        );

        // Waiting for reasons the rule cannot fix: not counted.
        $activator->enroll(
            Contact::factory()->create(['email' => null, 'email_status' => Contact::EMAIL_PENDING]),
            $automation,
        );
        $activator->enroll(
            Contact::factory()->create(['email' => 'dead@acme.example', 'email_status' => Contact::EMAIL_INVALID]),
            $automation,
        );

        $optedOut = Contact::factory()->create(['email' => 'no@acme.example', 'email_status' => Contact::EMAIL_PENDING]);
        $activator->enroll($optedOut, $automation);
        Suppression::suppress($optedOut->email, Suppression::REASON_MANUAL);

        Livewire::actingAs(User::factory()->create())
            ->test(Waterfall::class)
            ->call('toggleVerifiedEmail')
            ->assertSee('Start the 1 still waiting');
    }

    public function test_the_warning_counts_the_same_people_the_switch_then_starts(): void
    {
        Queue::fake();

        $automation = $this->automationWithAStep();
        $activator = app(EnrollmentActivator::class);

        foreach (['one@acme.example', 'two@acme.example'] as $email) {
            $activator->enroll(
                Contact::factory()->create(['email' => $email, 'email_status' => Contact::EMAIL_PENDING]),
                $automation,
            );
        }

        // The number on the warning and the number actually started have to be
        // the same, or the page promises one thing and does another.
        $warned = $activator->countWaitingOnConfirmationOnly();

        Livewire::actingAs(User::factory()->create())
            ->test(Waterfall::class)
            ->call('toggleVerifiedEmail')
            ->call('startEveryoneWaiting')
            ->assertSee($warned.' enrollment(s) have started.');

        $this->assertSame(2, $warned);
    }
}
