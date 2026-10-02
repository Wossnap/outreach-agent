<?php

namespace Tests\Feature;

use App\Jobs\EnrichContact;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Models\Suppression;
use App\Services\Enrichment\EnrichmentSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckerWithCredits;
use Tests\Support\FakeEnrichment;
use Tests\Support\FinderWithCredits;
use Tests\TestCase;

/**
 * Leads left waiting because nobody could answer for them are sent through
 * again, but only once somebody can, and never all at once.
 */
class RetryLookupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeEnrichment::reset();
        Queue::fake();

        config(['enrichment.drivers' => [
            'finder' => FinderWithCredits::class,
            'checker' => CheckerWithCredits::class,
        ]]);

        $this->provider('finder');
        $this->provider('checker');
    }

    private function provider(string $driver): EnrichmentProvider
    {
        return EnrichmentProvider::create([
            'name' => ucfirst($driver),
            'driver' => $driver,
            'position' => 1,
            'enabled' => true,
            'credentials' => ['api_key' => 'test-key'],
        ]);
    }

    /** A lead that has been waiting long enough to be picked up. */
    private function waiting(array $attributes = []): Contact
    {
        $contact = Contact::factory()->withoutAnEmail()->create(array_merge(
            ['email_status' => Contact::EMAIL_WAITING],
            $attributes,
        ));

        Contact::query()->whereKey($contact->id)->update(['updated_at' => now()->subHour()]);

        return $contact;
    }

    /** @return array<int, int> ids of the leads queued, in order */
    private function queued(): array
    {
        return Queue::pushed(EnrichContact::class)->map->contactId->values()->all();
    }

    public function test_waiting_leads_are_sent_through_again_spaced_out_and_in_a_limited_batch(): void
    {
        config(['enrichment.retry.batch' => 3, 'enrichment.retry.spacing_seconds' => 4]);
        $leads = collect(range(1, 5))->map(fn () => $this->waiting());

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertSame($leads->take(3)->pluck('id')->all(), $this->queued());

        // Spaced out, so sending is never stuck behind a wall of lookups.
        $delays = Queue::pushed(EnrichContact::class)
            ->map(fn (EnrichContact $job) => (int) round(now()->diffInSeconds($job->delay)))
            ->values()->all();
        $this->assertSame([0, 4, 8], $delays);

        // A retry never overrides what a provider has already answered.
        Queue::assertPushed(EnrichContact::class, fn (EnrichContact $job) => $job->askAgain === false);

        $this->assertSame(1, ActivityLog::query()->where('event', 'lookups_retried')->count());
    }

    public function test_nothing_is_sent_when_no_finder_or_checker_has_credit(): void
    {
        // Sean's rule: check first, and leave it alone when nobody can answer.
        FakeEnrichment::$credits = ['finder' => 0, 'checker' => 0];
        $this->waiting();
        $this->waiting(['email' => 'sam@acme.com']);

        $this->artisan('enrichment:retry')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_lead_is_only_sent_when_the_half_it_needs_can_work(): void
    {
        // Checkers have credit, finders do not: an address waiting for a check
        // goes, a lead still needing an address stays.
        FakeEnrichment::$credits = ['finder' => 0];
        $needsAnAddress = $this->waiting();
        $needsACheck = $this->waiting(['email' => 'sam@acme.com']);

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertSame([$needsACheck->id], $this->queued());
        $this->assertNotContains($needsAnAddress->id, $this->queued());
    }

    public function test_a_switched_off_provider_does_not_count_as_able_to_work(): void
    {
        EnrichmentProvider::query()->update(['enabled' => false]);
        $this->waiting();

        $this->artisan('enrichment:retry')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_topped_up_provider_is_switched_back_on_and_used_in_the_same_run(): void
    {
        // Hunter ran dry and the waterfall switched it off. It has been topped
        // up since: the next retry should not leave it idle until the hourly
        // check comes round.
        $finder = EnrichmentProvider::query()->where('driver', 'finder')->first();
        $finder->disableBecause('5 calls in a row failed. Check the key and the account balance.');
        $lead = $this->waiting();

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertTrue($finder->fresh()->enabled);
        $this->assertSame([$lead->id], $this->queued());
    }

    public function test_a_provider_switched_off_by_hand_is_not_switched_back_on(): void
    {
        $finder = EnrichmentProvider::query()->where('driver', 'finder')->first();
        $finder->update(['enabled' => false]);
        $this->waiting();

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertFalse($finder->fresh()->enabled);
        Queue::assertNothingPushed();
    }

    public function test_nobody_waiting_means_no_provider_is_asked_anything(): void
    {
        // Every fifteen minutes, all day: with nothing to do it should not
        // even make the free balance calls.
        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertSame(0, FakeEnrichment::$balanceChecks);
        Queue::assertNothingPushed();
    }

    public function test_a_lead_waiting_to_retry_is_picked_up_at_once(): void
    {
        // The waterfall put it there because it could not finish, so nothing
        // of its own is still queued: no reason to make it sit for a while.
        $lead = Contact::factory()->withoutAnEmail()->create(['email_status' => Contact::EMAIL_WAITING]);

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertSame([$lead->id], $this->queued());
    }

    public function test_a_lead_mid_lookup_is_only_picked_up_once_it_has_sat_there_too_long(): void
    {
        // A lookup takes seconds. Five minutes at "finding" could still be a
        // busy queue; an hour means the lookup died.
        $running = Contact::factory()->create(['email' => 'busy@acme.com', 'email_status' => Contact::EMAIL_FINDING]);
        Contact::query()->whereKey($running->id)->update(['updated_at' => now()->subMinutes(5)]);
        $died = $this->waiting(['email' => 'died@acme.com', 'email_status' => Contact::EMAIL_FINDING]);

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertSame([$died->id], $this->queued());
    }

    public function test_leads_stranded_mid_lookup_are_picked_up_too(): void
    {
        // Thousands were left at "finding" by the old code. They are waiting
        // just the same.
        $finding = $this->waiting(['email' => 'found@acme.com', 'email_status' => Contact::EMAIL_FINDING]);
        $verifying = $this->waiting(['email' => 'sam@acme.com', 'email_status' => Contact::EMAIL_VERIFYING]);

        $this->artisan('enrichment:retry')->assertSuccessful();

        $this->assertSame([$finding->id, $verifying->id], $this->queued());
    }

    public function test_settled_recent_and_opted_out_leads_are_left_alone(): void
    {
        $this->waiting(['email' => 'done@acme.com', 'email_status' => Contact::EMAIL_VALID]);
        $this->waiting(['email_status' => Contact::EMAIL_NOT_FOUND]);
        $this->waiting(['email' => 'gone@acme.com']);
        Suppression::suppress('gone@acme.com', 'unsubscribed');

        // Just pushed: its own lookup is still on the queue.
        Contact::factory()->withoutAnEmail()->create(['email_status' => Contact::EMAIL_PENDING]);

        $this->artisan('enrichment:retry')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_sent_while_lookups_are_switched_off(): void
    {
        EnrichmentSwitch::turnOff();
        $this->waiting();

        $this->artisan('enrichment:retry')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
