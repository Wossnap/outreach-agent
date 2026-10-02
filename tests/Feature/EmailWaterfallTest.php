<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\EmailWaterfall;
use App\Services\Enrichment\Verdict;
use App\Support\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillsEveryCall;
use Tests\Support\BillsOnlyForHits;
use Tests\Support\BrokenCatchAllSpecialist;
use Tests\Support\BrokenFinder;
use Tests\Support\BrokenVerifier;
use Tests\Support\CannotHelp;
use Tests\Support\DomainAcceptsNothing;
use Tests\Support\DomainAlwaysAcceptsMail;
use Tests\Support\FakeEnrichment;
use Tests\Support\FindsACheckedAddress;
use Tests\Support\FindsAnAddress;
use Tests\Support\FindsAnAddressWithExtras;
use Tests\Support\FindsNothing;
use Tests\Support\SaysCatchAll;
use Tests\Support\SaysInvalid;
use Tests\Support\SaysNothingUseful;
use Tests\Support\SaysValid;
use Tests\Support\SettlesCatchAlls;
use Tests\TestCase;

class EmailWaterfallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeEnrichment::reset();
        $this->app->bind(DnsResolver::class, DomainAlwaysAcceptsMail::class);

        config(['enrichment.drivers' => [
            'finds-nothing' => FindsNothing::class,
            'finds-an-address' => FindsAnAddress::class,
            'finds-an-address-with-extras' => FindsAnAddressWithExtras::class,
            'cannot-help' => CannotHelp::class,
            'broken-finder' => BrokenFinder::class,
            'says-valid' => SaysValid::class,
            'says-invalid' => SaysInvalid::class,
            'says-catch-all' => SaysCatchAll::class,
            'says-nothing-useful' => SaysNothingUseful::class,
            'broken-verifier' => BrokenVerifier::class,
            'bills-every-call' => BillsEveryCall::class,
            'bills-only-for-hits' => BillsOnlyForHits::class,
            'finds-a-checked-address' => FindsACheckedAddress::class,
            'settles-catch-alls' => SettlesCatchAlls::class,
            'broken-catch-all-specialist' => BrokenCatchAllSpecialist::class,
        ]]);
    }

    private function provider(string $driver, string $kind, array $attributes = []): EnrichmentProvider
    {
        return EnrichmentProvider::create(array_merge([
            'name' => $driver,
            'driver' => $driver,
            'kind' => $kind,
            'position' => EnrichmentProvider::count(),
            'enabled' => true,
            // A provider with no key is skipped, so every fake needs one.
            'credentials' => ['api_key' => 'test-key'],
        ], $attributes));
    }

    private function lead(array $attributes = []): Contact
    {
        return Contact::create(array_merge(['name' => 'Sam Carter', 'company' => 'Acme'], $attributes));
    }

    private function enrich(Contact $contact): Contact
    {
        app(EmailWaterfall::class)->run($contact);

        return $contact->fresh();
    }

    public function test_a_provider_with_no_key_is_skipped_rather_than_tried(): void
    {
        /*
         * Every provider the system knows about has a row, whether or not
         * anybody has signed up for it. Calling one with no key would fail every
         * time and eventually switch it off for failing, which reads as a broken
         * provider when the truth is an empty field.
         */
        $unconfigured = $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY, ['credentials' => null]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame([], FakeEnrichment::$calls);
        $this->assertFalse($unconfigured->lookups()->exists());
        $this->assertTrue($unconfigured->refresh()->enabled, 'It should not be blamed for having no key.');

        // Pending, not risky. Nobody was asked, so nothing is claimed, and the
        // lead can still be checked once somebody puts a key in.
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertFalse($contact->isEmailResolved());
    }

    public function test_a_confirmed_address_becomes_sendable(): void
    {
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
        $this->assertTrue(Contact::sendable()->whereKey($contact->id)->exists());
    }

    public function test_a_catch_all_domain_is_never_sendable(): void
    {
        // The whole reason catch-all is a separate verdict. The server accepts
        // every address it is offered, so a yes here says nothing about whether
        // this mailbox exists.
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_RISKY, $contact->email_status);
        $this->assertFalse(Contact::sendable()->whereKey($contact->id)->exists());
    }

    public function test_an_address_nobody_will_confirm_is_never_sendable(): void
    {
        $this->provider('says-nothing-useful', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_RISKY, $contact->email_status);
    }

    public function test_an_address_with_no_verifier_at_all_is_never_sendable(): void
    {
        // The absence of a no is not a yes. If this said valid, an empty
        // provider table would quietly mark every lead sendable.
        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertFalse(Contact::sendable()->whereKey($contact->id)->exists());

        /*
         * And waiting to retry rather than risky. Risky counts as settled, so a lead
         * that arrived while no verifier was configured would never be looked
         * at again once one was. An empty chain is a gap in the setup, not a
         * verdict about the address.
         */
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertFalse($contact->isEmailResolved());
    }

    public function test_a_second_verifier_rescues_an_address_the_first_would_not_confirm(): void
    {
        /*
         * The whole reason for having more than one. Greylisting and timeouts
         * make a provider shrug at an address that is perfectly real, and with
         * only one verifier that shrug becomes risky and the lead is never sent
         * to. Asking somebody else costs a fraction of a penny.
         */
        $this->provider('says-nothing-useful', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
        $this->assertSame(['says-nothing-useful', 'says-valid'], FakeEnrichment::$calls);
    }

    public function test_a_verifier_that_commits_ends_it_there(): void
    {
        // The second is not asked, and not paid. Falling through is for when
        // nobody would answer, not a second opinion on an answer we have.
        $this->provider('says-invalid', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $second = $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_INVALID, $contact->email_status);
        $this->assertFalse($second->lookups()->exists());
    }

    public function test_a_catch_all_is_passed_on_to_a_provider_that_can_resolve_it(): void
    {
        /*
         * Catch-all is not a verdict about the address. It says the domain
         * accepts everything offered to it, which is why a specialist can go
         * further and say whether that particular mailbox receives mail.
         *
         * If the first shrug settled the matter, that specialist would never be
         * asked and the money spent on it would buy nothing.
         */
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
        $this->assertSame(['says-catch-all', 'says-valid'], FakeEnrichment::$calls);
    }

    public function test_a_catch_all_nobody_can_resolve_stays_unsendable(): void
    {
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $this->provider('says-nothing-useful', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_RISKY, $contact->email_status);
    }

    public function test_a_rejected_address_is_marked_invalid(): void
    {
        $this->provider('says-invalid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_INVALID, $contact->email_status);
    }

    public function test_a_domain_that_cannot_receive_mail_costs_nothing_to_reject(): void
    {
        $this->app->bind(DnsResolver::class, DomainAcceptsNothing::class);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@nowhere.example']));

        $this->assertSame(Contact::EMAIL_INVALID, $contact->email_status);
        $this->assertSame([], FakeEnrichment::$calls, 'A paid verifier was called for a domain with nowhere to deliver.');
        $this->assertSame(0.0, (float) EmailLookup::sum('cost'));
    }

    public function test_finders_run_in_order_and_stop_at_the_first_hit(): void
    {
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND, ['position' => 2]);
        $second = $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND, ['position' => 3, 'name' => 'never reached']);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());

        $this->assertSame('found@acme.com', $contact->email);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
        $this->assertSame(['finds-nothing', 'finds-an-address', 'says-valid'], FakeEnrichment::$calls);
        $this->assertFalse($second->lookups()->exists());
    }

    public function test_job_title_and_company_are_filled_in_from_a_find_but_domain_is_not(): void
    {
        /*
         * Setting email, which every find does, derives domain from it and
         * keeps the two in step: a lead found at found@acme.com is given the
         * domain acme.com whatever it held before. job_title and company have
         * no such rule and are filled in only where blank.
         */
        $this->provider('finds-an-address-with-extras', EnrichmentProvider::KIND_FIND);

        $contact = $this->enrich($this->lead(['company' => null, 'domain' => 'already-known.com']));

        $this->assertSame('acme.com', $contact->domain);
        $this->assertSame('Acme', $contact->company);
        $this->assertSame('Head of Growth', $contact->job_title);
    }

    public function test_a_lead_is_left_alone_when_there_is_no_finder_to_ask(): void
    {
        // Not found means we looked and there was nothing. With no finder
        // configured nobody was asked, and writing the lead off for a gap in
        // the setup would lose it for good: settled leads are never revisited.
        $contact = $this->enrich($this->lead());

        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertFalse($contact->isEmailResolved());
    }

    public function test_a_lead_nobody_can_find_an_address_for_is_marked_not_found(): void
    {
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND);

        $contact = $this->enrich($this->lead());

        $this->assertSame(Contact::EMAIL_NOT_FOUND, $contact->email_status);
        $this->assertNull($contact->email);
    }

    public function test_a_provider_that_cannot_use_the_lead_is_skipped_rather_than_charged(): void
    {
        $cannot = $this->provider('cannot-help', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND, ['position' => 2]);

        $this->enrich($this->lead());

        $this->assertNotContains('cannot-help', FakeEnrichment::$calls);
        $this->assertFalse($cannot->lookups()->exists(), 'A provider that was never asked was billed anyway.');
    }

    public function test_a_failing_provider_does_not_stop_the_one_behind_it(): void
    {
        $broken = $this->provider('broken-finder', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND, ['position' => 2]);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());

        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);

        $failure = $broken->lookups()->sole();
        $this->assertSame(EmailLookup::RESULT_ERROR, $failure->result);
        // A call that failed bought nothing, whatever the billing says.
        $this->assertSame('0.000000', $failure->cost);
    }

    public function test_a_provider_that_keeps_failing_is_switched_off(): void
    {
        $broken = $this->provider('broken-verifier', EnrichmentProvider::KIND_VERIFY);

        for ($i = 0; $i < 5; $i++) {
            $this->enrich($this->lead(['email' => "sam{$i}@acme.com"]));
        }

        $broken->refresh();
        $this->assertFalse($broken->enabled);
        $this->assertStringContainsString('failed', $broken->disabled_reason);
        $this->assertNotNull($broken->disabled_at);
    }

    public function test_a_finder_that_fails_leaves_the_lead_waiting_rather_than_not_found(): void
    {
        // The case that happened: every finder out of credits. Not found is
        // settled for good and hidden by default, and nobody had looked.
        $this->provider('broken-finder', EnrichmentProvider::KIND_FIND);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());

        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertFalse($contact->isEmailResolved());
    }

    public function test_a_retry_does_not_ask_a_finder_that_already_found_nothing(): void
    {
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $broken = $this->provider('broken-finder', EnrichmentProvider::KIND_FIND, ['position' => 2]);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);

        // The failing one comes back. Only it is asked: the other has already
        // said it has nothing, and would only say so again.
        FakeEnrichment::reset();
        $broken->update(['driver' => 'finds-an-address']);

        $contact = $this->enrich($contact);

        $this->assertSame(['finds-an-address', 'says-valid'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
    }

    public function test_a_lead_every_switched_on_finder_has_answered_is_not_found_without_asking_again(): void
    {
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $broken = $this->provider('broken-finder', EnrichmentProvider::KIND_FIND, ['position' => 2]);

        $contact = $this->enrich($this->lead());
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);

        // Somebody takes the failing one out of the chain. Everybody left has
        // answered, so the lead is settled without paying anyone again.
        FakeEnrichment::reset();
        $broken->update(['enabled' => false]);

        $contact = $this->enrich($contact);

        $this->assertSame([], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_NOT_FOUND, $contact->email_status);
    }

    public function test_a_lead_waits_for_a_finder_that_was_switched_off_for_failing(): void
    {
        // Findymail said it had nothing; Hunter ran dry and switched itself
        // off. Hunter never looked, so this is not "not found": the lead
        // waits for Hunter's top-up instead of being written off for good.
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND, ['position' => 2])
            ->disableBecause('5 calls in a row failed. Check the key and the account balance.');

        $contact = $this->enrich($this->lead());

        $this->assertSame(['finds-nothing'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
    }

    public function test_a_catch_all_waits_for_a_specialist_that_was_switched_off_for_failing(): void
    {
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $this->provider('settles-catch-alls', EnrichmentProvider::KIND_VERIFY, ['position' => 2])
            ->disableBecause('5 calls in a row failed. Check the key and the account balance.');

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
    }

    public function test_a_checker_that_fails_leaves_the_address_waiting_rather_than_risky(): void
    {
        $this->provider('broken-verifier', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertSame('sam@acme.com', $contact->email);
    }

    public function test_a_found_address_waits_to_retry_when_there_is_no_checker(): void
    {
        // Not left at "finding", which says a lookup is under way when none
        // is, and which nothing would ever pick up again.
        $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND);

        $contact = $this->enrich($this->lead());

        $this->assertSame('found@acme.com', $contact->email);
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
    }

    public function test_a_retry_only_asks_the_checker_that_failed(): void
    {
        // The specialist that settles catch-alls is the one that failed.
        // Settling as risky here would have lost the one answer worth having.
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $specialist = $this->provider('broken-verifier', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);

        FakeEnrichment::reset();
        $specialist->update(['driver' => 'says-valid']);

        $contact = $this->enrich($contact);

        $this->assertSame(['says-valid'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
    }

    public function test_checking_again_by_hand_asks_everyone_afresh(): void
    {
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $this->provider('broken-finder', EnrichmentProvider::KIND_FIND, ['position' => 2]);

        $contact = $this->enrich($this->lead());
        FakeEnrichment::reset();

        app(EmailWaterfall::class)->run($contact->fresh(), askAgain: true);

        $this->assertSame(['finds-nothing', 'broken-finder'], FakeEnrichment::$calls);
    }

    public function test_a_finder_that_says_valid_is_taken_at_its_word(): void
    {
        // Hunter checks what it finds at no extra cost. Paying a verifier to
        // say "valid" again is exactly the spend this avoids.
        FakeEnrichment::$finderVerdict = Verdict::VALID;
        $this->provider('finds-a-checked-address', EnrichmentProvider::KIND_FIND);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());

        $this->assertSame(['finds-a-checked-address'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
        $this->assertSame('found@acme.com', $contact->email);
    }

    public function test_a_finder_that_is_unsure_sends_the_address_through_every_verifier(): void
    {
        FakeEnrichment::$finderVerdict = Verdict::UNKNOWN;
        $this->provider('finds-a-checked-address', EnrichmentProvider::KIND_FIND);
        $this->provider('says-nothing-useful', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $this->provider('settles-catch-alls', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead());

        $this->assertSame(['finds-a-checked-address', 'says-nothing-useful', 'settles-catch-alls'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
    }

    public function test_a_catch_all_from_the_finder_goes_straight_to_the_catch_all_specialist(): void
    {
        // The cheap verifier first in line would only say "catch-all" again.
        FakeEnrichment::$finderVerdict = Verdict::CATCH_ALL;
        $this->provider('finds-a-checked-address', EnrichmentProvider::KIND_FIND);
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $this->provider('settles-catch-alls', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead());

        $this->assertSame(['finds-a-checked-address', 'settles-catch-alls'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
    }

    public function test_a_catch_all_with_no_specialist_switched_on_is_risky_without_paying_anyone(): void
    {
        FakeEnrichment::$finderVerdict = Verdict::CATCH_ALL;
        $this->provider('finds-a-checked-address', EnrichmentProvider::KIND_FIND);
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());

        $this->assertSame(['finds-a-checked-address'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_RISKY, $contact->email_status);
    }

    public function test_a_catch_all_retried_after_the_specialist_failed_goes_straight_back_to_it(): void
    {
        // The retry has no fresh answer from Hunter in hand, only the address.
        // Without Hunter's saved verdict it would pay Reoon to say catch-all
        // again before reaching the one verifier that can settle it.
        FakeEnrichment::$finderVerdict = Verdict::CATCH_ALL;
        $this->provider('finds-a-checked-address', EnrichmentProvider::KIND_FIND);
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY, ['position' => 1]);
        $specialist = $this->provider('broken-catch-all-specialist', EnrichmentProvider::KIND_VERIFY, ['position' => 2]);

        $contact = $this->enrich($this->lead());
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertSame('found@acme.com', $contact->email);

        FakeEnrichment::reset();
        $specialist->update(['driver' => 'settles-catch-alls']);

        $contact = $this->enrich($contact);

        $this->assertSame(['settles-catch-alls'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
    }

    public function test_an_address_that_arrived_with_the_lead_is_verified_as_before(): void
    {
        // Nobody found it, so nobody checked it. The verifiers are the only check.
        $this->provider('finds-a-checked-address', EnrichmentProvider::KIND_FIND);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(['says-valid'], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_VALID, $contact->email_status);
    }

    public function test_a_switched_off_provider_is_not_used(): void
    {
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY, ['enabled' => false]);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame([], FakeEnrichment::$calls);
        $this->assertSame(Contact::EMAIL_WAITING, $contact->email_status);
        $this->assertFalse($contact->isEmailResolved());
    }

    public function test_a_settled_lead_is_never_paid_for_twice(): void
    {
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));
        $this->assertSame(1, EmailLookup::count());

        $this->enrich($contact);

        $this->assertSame(1, EmailLookup::count(), 'The waterfall ran again on a lead it had already settled.');
    }

    public function test_a_miss_is_free_when_the_provider_only_bills_for_hits(): void
    {
        $free = $this->provider('bills-only-for-hits', EnrichmentProvider::KIND_FIND);

        $this->enrich($this->lead());

        $this->assertSame('0.000000', $free->lookups()->sole()->cost);
    }

    public function test_a_miss_is_charged_when_the_provider_bills_for_every_call(): void
    {
        // How a provider bills is a fact about the provider, so it comes from
        // the driver rather than from a setting somebody filled in per account.
        $paid = $this->provider('bills-every-call', EnrichmentProvider::KIND_FIND);

        $this->enrich($this->lead());

        $this->assertSame('0.010000', $paid->lookups()->sole()->cost);
    }

    public function test_what_a_verifier_said_is_kept_with_its_verdict(): void
    {
        // A verdict nobody can see the reasoning behind is one nobody can argue
        // with. When somebody insists an address marked risky is fine, this is
        // the only thing there is to look at.
        $this->provider('says-catch-all', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead(['email' => 'sam@acme.com']));

        $this->assertSame(['said' => 'catch-all'], $contact->lookups()->sole()->detail);
    }

    public function test_every_attempt_is_recorded_whether_it_worked_or_not(): void
    {
        // The misses are the point: a provider that is cheap per call and finds
        // nothing is expensive per address found, and that is invisible unless
        // the failures are counted too.
        $this->provider('finds-nothing', EnrichmentProvider::KIND_FIND, ['position' => 1]);
        $this->provider('finds-an-address', EnrichmentProvider::KIND_FIND, ['position' => 2]);
        $this->provider('says-valid', EnrichmentProvider::KIND_VERIFY);

        $contact = $this->enrich($this->lead());

        $this->assertSame(3, $contact->lookups()->count());
        $this->assertEqualsCanonicalizing(
            [EmailLookup::RESULT_NOTHING, EmailLookup::RESULT_FOUND, EmailLookup::RESULT_VALID],
            $contact->lookups()->pluck('result')->all(),
        );
    }
}
