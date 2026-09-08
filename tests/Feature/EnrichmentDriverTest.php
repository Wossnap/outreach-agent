<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Drivers\BounceBanVerifier;
use App\Services\Enrichment\Drivers\FindymailFinder;
use App\Services\Enrichment\Drivers\HunterFinder;
use App\Services\Enrichment\Drivers\MyEmailVerifierVerifier;
use App\Services\Enrichment\Drivers\ReoonVerifier;
use App\Services\Enrichment\Drivers\ZeroBounceVerifier;
use App\Services\Enrichment\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EnrichmentDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here may reach the internet. A driver test that quietly calls
        // a real API is a test that fails when somebody else has an outage.
        Http::preventStrayRequests();
    }

    private function provider(string $driver, string $kind): EnrichmentProvider
    {
        return EnrichmentProvider::create([
            'name' => $driver,
            'driver' => $driver,
            'kind' => $kind,
            'credentials' => ['api_key' => 'test-key'],
        ]);
    }

    private function lead(array $attributes = []): Contact
    {
        return Contact::create(array_merge(['name' => 'Sam Carter', 'email' => 'sam@acme.com'], $attributes));
    }

    public function test_hunter_returns_the_address_it_found(): void
    {
        Http::fake(['api.hunter.io/*' => Http::response([
            'data' => ['email' => 'sam.carter@acme.com', 'score' => 94, 'sources' => [[], []]],
        ])]);

        $found = (new HunterFinder)->find($this->lead(), $this->provider('hunter', EnrichmentProvider::KIND_FIND));

        $this->assertSame('sam.carter@acme.com', $found->email);
        $this->assertSame(94, $found->confidence);
        $this->assertSame(2, $found->detail['sources']);
    }

    public function test_hunter_finding_nobody_is_a_miss_not_a_failure(): void
    {
        // Hunter answers 404 when it has no address for the person. That is the
        // ordinary case, and the waterfall should move on quietly.
        Http::fake(['api.hunter.io/*' => Http::response([], 404)]);

        $this->assertNull(
            (new HunterFinder)->find($this->lead(), $this->provider('hunter', EnrichmentProvider::KIND_FIND))
        );
    }

    public function test_hunter_refusing_us_is_a_failure_not_a_miss(): void
    {
        /*
         * A bad key or an empty account must not look like a provider that
         * simply never finds anybody. One is a thing to fix; the other is a
         * reason to reorder the waterfall, and confusing them means paying a
         * worse provider while the better one sits there switched on and
         * broken.
         */
        Http::fake(['api.hunter.io/*' => Http::response(['errors' => [['details' => 'No credits']]], 402)]);

        $this->expectException(RuntimeException::class);

        (new HunterFinder)->find($this->lead(), $this->provider('hunter', EnrichmentProvider::KIND_FIND));
    }

    public function test_hunter_is_skipped_when_there_is_nothing_to_search_by(): void
    {
        $finder = new HunterFinder;

        // A LinkedIn URL on its own is enough.
        $this->assertTrue($finder->supports(Contact::create(['profile_url' => 'https://linkedin.com/in/sam'])));

        // A company page is not a person, and a bare domain with nobody's
        // name to search for is not searchable either.
        $this->assertFalse($finder->supports(Contact::create(['profile_url' => 'https://linkedin.com/company/acme'])));
        $this->assertFalse($finder->supports(Contact::create(['email' => 'nobody@acme.com'])));

        $this->assertTrue($finder->supports($this->lead()));
    }

    public function test_hunter_prefers_a_linkedin_handle_even_when_a_domain_is_present(): void
    {
        /*
         * A name and a domain can match more than one person; a LinkedIn
         * handle names exactly one. So it is used first whenever there is
         * one, not only when there is nothing else.
         */
        Http::fake(['api.hunter.io/*' => Http::response(['data' => ['email' => 'sam@acme.com']])]);

        $contact = $this->lead(['profile_url' => 'https://www.linkedin.com/in/sam-carter-4b2']);

        (new HunterFinder)->find($contact, $this->provider('hunter', EnrichmentProvider::KIND_FIND));

        Http::assertSent(function ($request) {
            return $request['linkedin_handle'] === 'sam-carter-4b2'
                && ! isset($request['domain'])
                && ! isset($request['full_name']);
        });
    }

    public function test_hunter_falls_back_to_domain_and_name_without_a_linkedin_url(): void
    {
        Http::fake(['api.hunter.io/*' => Http::response(['data' => ['email' => 'sam@acme.com']])]);

        (new HunterFinder)->find($this->lead(), $this->provider('hunter', EnrichmentProvider::KIND_FIND));

        Http::assertSent(function ($request) {
            return $request['domain'] === 'acme.com'
                && $request['full_name'] === 'Sam Carter'
                && ! isset($request['linkedin_handle']);
        });
    }

    public function test_hunter_reads_the_handle_out_of_a_full_url(): void
    {
        Http::fake(['api.hunter.io/*' => Http::response(['data' => ['email' => 'sam@acme.com']])]);

        $contact = Contact::create([
            'name' => 'Sam Carter',
            'profile_url' => 'https://www.linkedin.com/in/sam-carter-4b2/?trk=abc',
        ]);

        (new HunterFinder)->find($contact, $this->provider('hunter', EnrichmentProvider::KIND_FIND));

        // Neither the trailing slash nor the tracking parameter belong to the
        // handle itself.
        Http::assertSent(fn ($request) => $request['linkedin_handle'] === 'sam-carter-4b2');
    }

    public function test_hunter_returns_the_domain_and_company_it_found(): void
    {
        // A lead searched by handle alone has no domain going in. Hunter
        // sends one back regardless of how it was asked.
        Http::fake(['api.hunter.io/*' => Http::response([
            'data' => ['email' => 'sam@acme.com', 'domain' => 'acme.com', 'company' => 'Acme'],
        ])]);

        $contact = Contact::create(['name' => 'Sam Carter', 'profile_url' => 'https://linkedin.com/in/sam-carter']);

        $found = (new HunterFinder)->find($contact, $this->provider('hunter', EnrichmentProvider::KIND_FIND));

        $this->assertSame('acme.com', $found->extra['domain']);
        $this->assertSame('Acme', $found->extra['company']);
    }

    #[DataProvider('reoonStatuses')]
    public function test_reoon_verdicts(string $status, Verdict $expected): void
    {
        Http::fake(['emailverifier.reoon.com/*' => Http::response(['status' => $status])]);

        $this->assertSame(
            $expected,
            (new ReoonVerifier)->verify('sam@acme.com', $this->provider('reoon', EnrichmentProvider::KIND_VERIFY))->verdict,
        );
    }

    public static function reoonStatuses(): array
    {
        return [
            'valid' => ['valid', Verdict::VALID],
            'safe' => ['safe', Verdict::VALID],
            'invalid' => ['invalid', Verdict::INVALID],
            'disposable' => ['disposable', Verdict::INVALID],
            'spamtrap' => ['spamtrap', Verdict::INVALID],
            'catch_all' => ['catch_all', Verdict::CATCH_ALL],
            'role account' => ['role_account', Verdict::CATCH_ALL],
            'unknown' => ['unknown', Verdict::UNKNOWN],

            // Real, and cannot receive mail today. Not sendable, and not
            // settled as dead either: the box may be emptied tomorrow, and a
            // lead marked invalid is never looked at again.
            'inbox full' => ['inbox_full', Verdict::UNKNOWN],

            'disabled account' => ['disabled', Verdict::INVALID],

            // The one that matters most. A status this code has never seen
            // must never fall through to valid: being wrong that way sends
            // mail to an address nobody confirmed, which is the entire failure
            // this system exists to prevent. Being wrong the other way costs
            // one more lookup.
            'something new' => ['some_status_added_next_year', Verdict::UNKNOWN],
        ];
    }

    public function test_reoon_is_always_asked_for_the_deep_check(): void
    {
        // Quick mode marks every address at a good domain as valid, including
        // ones that do not exist. It would hand back confident yeses for dead
        // mailboxes, which is the failure this whole system exists to prevent.
        Http::fake(['emailverifier.reoon.com/*' => Http::response(['status' => 'safe'])]);

        $provider = $this->provider('reoon', EnrichmentProvider::KIND_VERIFY);
        $provider->update(['credentials' => ['api_key' => 'test-key', 'mode' => 'quick']]);

        (new ReoonVerifier)->verify('sam@acme.com', $provider);

        Http::assertSent(fn ($request): bool => $request['mode'] === 'power');
    }

    #[DataProvider('zeroBounceStatuses')]
    public function test_zerobounce_verdicts(string $status, Verdict $expected): void
    {
        Http::fake(['api.zerobounce.net/*' => Http::response(['status' => $status])]);

        $this->assertSame(
            $expected,
            (new ZeroBounceVerifier)->verify('sam@acme.com', $this->provider('zerobounce', EnrichmentProvider::KIND_VERIFY))->verdict,
        );
    }

    public static function zeroBounceStatuses(): array
    {
        return [
            'valid' => ['valid', Verdict::VALID],
            'invalid' => ['invalid', Verdict::INVALID],

            // Mail to a spamtrap damages the sending domain far more than a
            // bounce does: they exist to catch senders who bought a list.
            'spamtrap' => ['spamtrap', Verdict::INVALID],
            'abuse' => ['abuse', Verdict::INVALID],
            'do not mail' => ['do_not_mail', Verdict::INVALID],

            'catch-all' => ['catch-all', Verdict::CATCH_ALL],

            // Greylisted or timed out: a fact about this moment, not about the
            // address, so it passes to whoever is next rather than settling.
            'unknown' => ['unknown', Verdict::UNKNOWN],

            'something new' => ['some_status_added_next_year', Verdict::UNKNOWN],
        ];
    }

    public function test_zerobounce_running_out_of_credit_is_a_failure_not_a_shrug(): void
    {
        // An exhausted account answers 200 with an error in the body. Reading
        // only the status code would let a dead key look like a provider that
        // simply never has an opinion, and it would stay switched on.
        Http::fake(['api.zerobounce.net/*' => Http::response([
            'error' => 'Invalid API Key or your account ran out of credits',
        ])]);

        $this->expectException(RuntimeException::class);

        (new ZeroBounceVerifier)->verify('sam@acme.com', $this->provider('zerobounce', EnrichmentProvider::KIND_VERIFY));
    }

    public function test_findymail_returns_the_address_it_found(): void
    {
        Http::fake(['app.findymail.com/*' => Http::response([
            'contact' => ['name' => 'Sam Carter', 'email' => 'sam@acme.com', 'domain' => 'acme.com'],
        ])]);

        $contact = $this->lead(['email' => null, 'domain' => 'acme.com']);
        $found = (new FindymailFinder)->find($contact, $this->provider('findymail', EnrichmentProvider::KIND_FIND));

        $this->assertSame('sam@acme.com', $found->email);
    }

    public function test_findymail_finding_nobody_is_a_miss(): void
    {
        Http::fake(['app.findymail.com/*' => Http::response(['contact' => null])]);

        $contact = $this->lead(['email' => null, 'domain' => 'acme.com']);

        $this->assertNull(
            (new FindymailFinder)->find($contact, $this->provider('findymail', EnrichmentProvider::KIND_FIND))
        );
    }

    public function test_findymail_is_skipped_without_a_name_and_domain_or_a_linkedin_url(): void
    {
        $finder = new FindymailFinder;

        // A LinkedIn URL on its own is enough.
        $this->assertTrue($finder->supports(Contact::create(['profile_url' => 'https://linkedin.com/in/sam'])));
        $this->assertTrue($finder->supports($this->lead(['domain' => 'acme.com'])));
        $this->assertFalse($finder->supports(Contact::create(['email' => 'nobody@acme.com'])));

        // A company page is not a person, and looking one up as a person
        // costs a call to answer nothing.
        $this->assertFalse($finder->supports(
            Contact::create(['profile_url' => 'https://www.linkedin.com/company/acme/posts'])
        ));
    }

    public function test_findymail_ignores_a_company_page_and_uses_the_name_and_domain(): void
    {
        Http::fake(['app.findymail.com/*' => Http::response(['contact' => ['email' => 'sam@acme.com']])]);

        $contact = $this->lead([
            'domain' => 'acme.com',
            'profile_url' => 'https://www.linkedin.com/company/acme/posts',
        ]);

        (new FindymailFinder)->find($contact, $this->provider('findymail', EnrichmentProvider::KIND_FIND));

        Http::assertSent(fn ($request) => $request->url() === 'https://app.findymail.com/api/search/name'
            && $request['domain'] === 'acme.com');
    }

    public function test_findymail_prefers_the_linkedin_url_even_when_a_domain_is_present(): void
    {
        Http::fake(['app.findymail.com/*' => Http::response(['contact' => ['email' => 'sam@acme.com']])]);

        $contact = $this->lead(['domain' => 'acme.com', 'profile_url' => 'https://linkedin.com/in/sam-carter']);

        (new FindymailFinder)->find($contact, $this->provider('findymail', EnrichmentProvider::KIND_FIND));

        Http::assertSent(fn ($request) => $request->url() === 'https://app.findymail.com/api/search/business-profile'
            && $request['linkedin_url'] === 'https://linkedin.com/in/sam-carter');
    }

    public function test_findymail_falls_back_to_name_and_domain_without_a_linkedin_url(): void
    {
        Http::fake(['app.findymail.com/*' => Http::response(['contact' => ['email' => 'sam@acme.com']])]);

        (new FindymailFinder)->find($this->lead(['domain' => 'acme.com']), $this->provider('findymail', EnrichmentProvider::KIND_FIND));

        Http::assertSent(fn ($request) => $request->url() === 'https://app.findymail.com/api/search/name'
            && $request['domain'] === 'acme.com');
    }

    public function test_findymail_returns_the_domain_it_found(): void
    {
        // A lead searched by LinkedIn URL alone has no domain going in.
        // Findymail sends one back regardless of how it was asked.
        Http::fake(['app.findymail.com/*' => Http::response([
            'contact' => ['email' => 'sam@acme.com', 'domain' => 'acme.com'],
        ])]);

        $contact = Contact::create(['name' => 'Sam Carter', 'profile_url' => 'https://linkedin.com/in/sam-carter']);

        $found = (new FindymailFinder)->find($contact, $this->provider('findymail', EnrichmentProvider::KIND_FIND));

        $this->assertSame('acme.com', $found->extra['domain']);
    }

    #[DataProvider('myEmailVerifierBodies')]
    public function test_myemailverifier_verdicts(array $body, Verdict $expected): void
    {
        Http::fake(['api.myemailverifier.com/*' => Http::response($body)]);

        $this->assertSame(
            $expected,
            (new MyEmailVerifierVerifier)->verify('sam@acme.com', $this->provider('myemailverifier', EnrichmentProvider::KIND_VERIFY))->verdict,
        );
    }

    public static function myEmailVerifierBodies(): array
    {
        $base = [
            'Status' => 'Valid',
            'catch_all' => 'false',
            'Disposable_Domain' => 'false',
            'Role_Based' => 'false',
            'Greylisted' => 'false',
        ];

        return [
            'valid' => [$base, Verdict::VALID],
            'invalid' => [[...$base, 'Status' => 'Invalid'], Verdict::INVALID],

            /*
             * The three that override the status entirely. Their booleans
             * arrive as the strings "true" and "false", which a plain cast
             * would read as true either way, so every address would come back
             * catch-all.
             */
            'catch-all beats a valid status' => [[...$base, 'catch_all' => 'true'], Verdict::CATCH_ALL],
            'role account beats a valid status' => [[...$base, 'Role_Based' => 'true'], Verdict::CATCH_ALL],
            'greylisted beats a valid status' => [[...$base, 'Greylisted' => 'true'], Verdict::UNKNOWN],
            'disposable beats everything' => [[...$base, 'Disposable_Domain' => 'true', 'catch_all' => 'true'], Verdict::INVALID],

            // Their documentation shows one status and lists no others, so
            // anything unrecognised must never fall through to valid.
            'a status nobody documented' => [[...$base, 'Status' => 'Deliverable'], Verdict::UNKNOWN],
            'false is not true' => [[...$base, 'catch_all' => 'false'], Verdict::VALID],

            /*
             * What the API really sends. Their published sample shows the
             * strings "true" and "false"; the live service returns 1 and 0.
             * Both shapes are read the same way here, which is the only reason
             * the difference was harmless.
             */
            'integers, which is what really comes back' => [[
                'Status' => 'Valid',
                'catch_all' => 0,
                'Disposable_Domain' => 0,
                'Role_Based' => 0,
                'Greylisted' => 0,
            ], Verdict::VALID],
            'a catch-all as an integer' => [[
                'Status' => 'Valid',
                'catch_all' => 1,
                'Disposable_Domain' => 0,
                'Role_Based' => 0,
                'Greylisted' => 0,
            ], Verdict::CATCH_ALL],
        ];
    }

    public function test_myemailverifier_sending_no_verdict_is_a_failure(): void
    {
        // A wrong key answers 200 with prose rather than a verdict. Reading
        // that as "no opinion" would leave a dead provider switched on.
        Http::fake(['api.myemailverifier.com/*' => Http::response(['error' => 'Invalid API key'])]);

        $this->expectException(RuntimeException::class);

        (new MyEmailVerifierVerifier)->verify('sam@acme.com', $this->provider('myemailverifier', EnrichmentProvider::KIND_VERIFY));
    }

    #[DataProvider('bounceBanBodies')]
    public function test_bounceban_verdicts(array $body, Verdict $expected): void
    {
        Http::fake(['api-waterfall.bounceban.com/*' => Http::response($body)]);

        $this->assertSame(
            $expected,
            (new BounceBanVerifier)->verify('sam@acme.com', $this->provider('bounceban', EnrichmentProvider::KIND_VERIFY))->verdict,
        );
    }

    public static function bounceBanBodies(): array
    {
        $base = ['status' => 'success', 'is_disposable' => false, 'is_accept_all' => false];

        return [
            'deliverable' => [[...$base, 'result' => 'deliverable', 'score' => 99], Verdict::VALID],

            /*
             * The reason this provider exists. Every other verifier answers
             * catch-all and stops; this one says the mailbox does not receive
             * mail, which is a real answer about a domain that accepts
             * everything offered to it.
             */
            'a catch-all resolved as dead' => [
                [...$base, 'result' => 'undeliverable', 'score' => 0, 'is_accept_all' => true],
                Verdict::INVALID,
            ],
            'a catch-all resolved as alive' => [
                [...$base, 'result' => 'deliverable', 'score' => 92, 'is_accept_all' => true],
                Verdict::VALID,
            ],

            'risky' => [[...$base, 'result' => 'risky', 'score' => 40], Verdict::CATCH_ALL],
            'unknown' => [[...$base, 'result' => 'unknown', 'score' => -1], Verdict::UNKNOWN],
            'disposable beats deliverable' => [
                [...$base, 'result' => 'deliverable', 'is_disposable' => true],
                Verdict::INVALID,
            ],
            'a result nobody documented' => [[...$base, 'result' => 'brand_new'], Verdict::UNKNOWN],
        ];
    }

    public function test_bounceban_still_working_is_not_a_failure(): void
    {
        /*
         * A 408 means the verification is still running, not that it broke. It
         * continues in the background and asking again within thirty minutes is
         * free. Counting it as a failure would eventually switch off the
         * provider for being slow, which is what it is for.
         */
        Http::fake(['api-waterfall.bounceban.com/*' => Http::response(['id' => 'wf1'], 408)]);

        $result = (new BounceBanVerifier)->verify('sam@acme.com', $this->provider('bounceban', EnrichmentProvider::KIND_VERIFY));

        $this->assertSame(Verdict::UNKNOWN, $result->verdict);

        // And says why, so a lead sitting unresolved is explicable rather than
        // just blank.
        $this->assertStringContainsString('Still verifying', $result->detail['note']);
    }

    public function test_bounceban_gets_the_key_without_a_bearer_prefix(): void
    {
        // Their API takes the key bare. withToken would send "Bearer <key>"
        // and every call would come back unauthorised.
        Http::fake(['api-waterfall.bounceban.com/*' => Http::response(['status' => 'success', 'result' => 'deliverable'])]);

        (new BounceBanVerifier)->verify('sam@acme.com', $this->provider('bounceban', EnrichmentProvider::KIND_VERIFY));

        Http::assertSent(fn ($request): bool => $request->header('Authorization')[0] === 'test-key');
    }

    public function test_reoon_reporting_its_own_error_is_a_failure(): void
    {
        Http::fake(['emailverifier.reoon.com/*' => Http::response([
            'status' => 'error',
            'reason' => 'Invalid API key',
        ])]);

        $this->expectException(RuntimeException::class);

        (new ReoonVerifier)->verify('sam@acme.com', $this->provider('reoon', EnrichmentProvider::KIND_VERIFY));
    }
}
