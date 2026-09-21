<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Jobs\EnrichContact;
use App\Livewire\Contacts\Index;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Inbound\InboundEmail;
use App\Services\Inbound\InboundProcessor;
use App\Services\Sending\EnrollmentActivator;
use App\Services\Sending\VerifiedEmailSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the rest of the system does with a lead who has no address.
 *
 * The address is optional, and a great deal of code reads it, matches on it or
 * filters by it. Each case here is a screen or an endpoint being given somebody
 * the scraper found, with no address at all, and being expected to cope.
 *
 * The other half of the theme is the enrollment that waits for an address.
 * Waiting is an open state - it is why the person cannot be enrolled twice -
 * and anything that ends an active enrollment has to end a waiting one too.
 */
class LeadsWithNoAddressTest extends TestCase
{
    use RefreshDatabase;

    private Contact $nameless;

    private Automation $automation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->automation = Automation::factory()->create(['tag' => 'job-change']);
        $this->nameless = Contact::factory()->withoutAnEmail()->create([
            'name' => 'Sam Carter',
            'source' => 'linkedin',
        ]);
    }

    private function waitingEnrollment(?Contact $contact = null): Enrollment
    {
        return Enrollment::factory()->create([
            'contact_id' => ($contact ?? $this->nameless)->id,
            'automation_id' => $this->automation->id,
            'mailbox_id' => null,
            'status' => Enrollment::STATUS_WAITING_EMAIL,
        ]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer '.User::factory()->create()->createToken('t', ['read', 'write'])->plainTextToken];
    }

    public function test_the_not_suppressed_filter_keeps_leads_that_have_no_address(): void
    {
        // NOT IN is never true for a null, so people with no address have to
        // be asked for separately or they are missing from this filter.
        Livewire::test(Index::class)
            ->set('suppressed', 'no')
            ->assertSee('Sam Carter');
    }

    public function test_the_read_api_keeps_them_too(): void
    {
        $this->getJson('/api/contacts?suppressed=false', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sam Carter');
    }

    public function test_suppressing_a_lead_with_no_address_says_what_it_did(): void
    {
        // The opt-out list is keyed on the address, and this person has none
        // to add. The half that means something - stopping whatever they are
        // in - still happens, and is reported rather than left to be guessed.
        $this->waitingEnrollment();

        $flash = Livewire::test(Index::class)
            ->call('suppress', $this->nameless->id)
            ->get('flash');

        $this->assertStringContainsString('no address', (string) $flash);
        $this->assertSame(Enrollment::STATUS_STOPPED_SUPPRESSED, Enrollment::sole()->status);
    }

    public function test_suppressing_stops_an_enrollment_that_is_still_waiting(): void
    {
        $contact = Contact::factory()->pending()->create(['email' => 'sam@acme.com']);
        $this->waitingEnrollment($contact);

        Livewire::test(Index::class)->call('suppress', $contact->id);

        // Left waiting, it would have started the day an address was confirmed
        // for somebody who had just been told never to be emailed again.
        $this->assertSame(Enrollment::STATUS_STOPPED_SUPPRESSED, Enrollment::sole()->status);
    }

    public function test_a_bounce_stops_an_enrollment_that_is_still_waiting(): void
    {
        $contact = Contact::factory()->create(['email' => 'dead@globex.com']);
        $this->waitingEnrollment($contact);

        app(InboundProcessor::class)->process(
            Mailbox::factory()->connected()->create(),
            new InboundEmail(
                gmailMessageId: 'b1',
                gmailThreadId: null,
                fromEmail: 'mailer-daemon@googlemail.com',
                subject: 'Delivery Status Notification (Failure)',
                snippet: 'failed',
                bodyText: "Final-Recipient: rfc822; dead@globex.com\nAction: failed",
                headers: [],
                labelIds: ['INBOX'],
            ),
        );

        $this->assertSame(Enrollment::STATUS_STOPPED_BOUNCE, Enrollment::sole()->status);
    }

    public function test_an_unsubscribe_stops_an_enrollment_that_is_still_waiting(): void
    {
        $contact = Contact::factory()->create(['email' => 'sam@acme.com']);
        $this->waitingEnrollment($contact);

        app(InboundProcessor::class)->process(
            Mailbox::factory()->connected()->create(),
            new InboundEmail(
                gmailMessageId: 'u1',
                gmailThreadId: null,
                fromEmail: 'sam@acme.com',
                subject: 'unsubscribe',
                snippet: 'remove me',
                bodyText: 'Please unsubscribe me from this list',
                headers: [],
                labelIds: ['INBOX'],
            ),
        );

        $this->assertSame(Enrollment::STATUS_STOPPED_UNSUBSCRIBE, Enrollment::sole()->status);
    }

    public function test_a_waiting_enrollment_can_be_called_off_over_the_api(): void
    {
        // A caller who has changed their mind about somebody should not have
        // to wait for an address to turn up before they can say so.
        $enrollment = $this->waitingEnrollment();

        $this->deleteJson('/api/enrollments/'.$enrollment->id, [], $this->apiHeaders())->assertOk();

        $this->assertSame(Enrollment::STATUS_CANCELLED, $enrollment->fresh()->status);
    }

    public function test_enrolling_somebody_with_no_address_over_the_api_waits(): void
    {
        Queue::fake();
        Automation::factory()->create(['tag' => 'second']);

        // A 500 before this: the suppression check was handed a null address.
        $this->postJson('/api/enrollments', [
            'contact_id' => $this->nameless->id,
            'tag' => 'second',
        ], $this->apiHeaders())
            ->assertStatus(201)
            ->assertJsonPath('data.status', Enrollment::STATUS_WAITING_EMAIL);
    }

    public function test_the_screen_can_be_filtered_down_to_who_is_waiting(): void
    {
        $this->waitingEnrollment();
        $other = Contact::factory()->create(['email' => 'active@acme.com']);
        Enrollment::factory()->create(['contact_id' => $other->id, 'automation_id' => $this->automation->id]);

        Livewire::test(Index::class)
            // The status list lives inside the filter panel, which starts open.
            ->assertSee(Enrollment::STATUS_WAITING_EMAIL)
            ->set('enrollmentStatuses', [Enrollment::STATUS_WAITING_EMAIL])
            ->assertSee('Sam Carter')
            ->assertDontSee('active@acme.com');
    }

    public function test_the_stats_api_counts_who_is_waiting(): void
    {
        $this->waitingEnrollment();

        $this->getJson('/api/stats', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.enrollments.waiting_email', 1)
            ->assertJsonPath('data.enrollments.active', 0);
    }

    public function test_rechecking_says_a_sentence_rather_than_an_address(): void
    {
        Queue::fake();
        $contact = Contact::factory()->pending()->create(['email' => 'sam@acme.com']);

        // "??" binds tighter than ".", so the explanation was appended only for
        // a lead that had no address, and everybody else saw a bare email.
        $flash = Livewire::test(Index::class)->call('recheck', $contact->id)->get('flash');

        $this->assertStringContainsString('sam@acme.com', (string) $flash);
        $this->assertStringContainsString('queued', (string) $flash);
    }

    public function test_rechecking_does_not_spend_money_on_somebody_who_opted_out(): void
    {
        Queue::fake();
        $contact = Contact::factory()->pending()->create(['email' => 'gone@acme.com']);
        Suppression::suppress('gone@acme.com', Suppression::REASON_UNSUBSCRIBED);

        Livewire::test(Index::class)->call('recheck', $contact->id);

        // Every provider it reached would charge us for an answer we could
        // never use.
        Queue::assertNotPushed(EnrichContact::class);
    }

    public function test_an_address_known_to_be_dead_is_never_sendable(): void
    {
        // The switch decides how much proof is wanted before sending. Neither
        // setting is an argument for sending to a mailbox a verifier rejected
        // or a message has already bounced off.
        $dead = Contact::factory()->create([
            'email' => 'dead@globex.com',
            'email_status' => Contact::EMAIL_INVALID,
        ]);

        VerifiedEmailSwitch::turnOff();

        $this->assertFalse($dead->isSendable());
        $this->assertSame(0, Contact::query()->sendable()->where('id', $dead->id)->count());
    }

    public function test_enrolling_somebody_who_opted_out_never_starts_a_sequence(): void
    {
        Queue::fake();

        $automation = Automation::factory()->create(['active' => true]);
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 1]);

        $contact = Contact::factory()->create([
            'email' => 'opted.out@acme.example',
            'email_status' => Contact::EMAIL_VALID,
        ]);
        Suppression::suppress($contact->email, Suppression::REASON_MANUAL);

        // Straight at the service, deliberately. Both of its callers check
        // suppression before they get here, so this is the only way to ask
        // whether the service is safe on its own - and it was not: a valid
        // address was enough to make it active and draft the first email.
        $enrollment = app(EnrollmentActivator::class)->enroll($contact->fresh(), $automation);

        $this->assertSame(Enrollment::STATUS_WAITING_EMAIL, $enrollment->status);
        Queue::assertNotPushed(DraftEmailJob::class);
    }
}
