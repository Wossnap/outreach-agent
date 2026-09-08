<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Contracts\EmailFinder;
use App\Services\Enrichment\Contracts\EmailVerifier;
use App\Services\Enrichment\Contracts\PublishesListPrice;
use App\Services\Enrichment\FoundEmail;
use App\Services\Enrichment\ListPrice;
use App\Services\Enrichment\Verdict;
use App\Services\Enrichment\Verification;
use App\Support\Dns\DnsResolver;
use RuntimeException;

/**
 * Stand-ins for the providers, so the waterfall's own behaviour can be tested
 * without any of it depending on somebody else's uptime, pricing or DNS.
 *
 * Each records that it was called, which is how the tests assert on what the
 * waterfall skipped as well as what it ran.
 */
class FakeEnrichment
{
    /** @var array<int, string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

class FindsNothing implements EmailFinder
{
    public function supports(Contact $contact): bool
    {
        return true;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'finds-nothing';

        return null;
    }
}

class FindsAnAddress implements EmailFinder
{
    public function supports(Contact $contact): bool
    {
        return true;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'finds-an-address';

        return new FoundEmail('found@acme.com', 88, ['score' => 88]);
    }
}

/**
 * A finder that also reports what it knows about the person, the way a real
 * one does when it is asked with only a LinkedIn URL and hands back the
 * company it found along with the address.
 */
class FindsAnAddressWithExtras implements EmailFinder
{
    public function supports(Contact $contact): bool
    {
        return true;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'finds-an-address-with-extras';

        return new FoundEmail('found@acme.com', 88, ['score' => 88], [
            'job_title' => 'Head of Growth',
            'company' => 'Acme',
            'domain' => 'acme.com',
        ]);
    }
}

class CannotHelp implements EmailFinder
{
    public function supports(Contact $contact): bool
    {
        return false;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'cannot-help';

        return null;
    }
}

class BrokenFinder implements EmailFinder
{
    public function supports(Contact $contact): bool
    {
        return true;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'broken-finder';

        throw new RuntimeException('402 Payment Required');
    }
}

class SaysValid implements EmailVerifier
{
    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        FakeEnrichment::$calls[] = 'says-valid';

        return new Verification(Verdict::VALID, ['said' => 'valid']);
    }
}

class SaysInvalid implements EmailVerifier
{
    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        FakeEnrichment::$calls[] = 'says-invalid';

        return new Verification(Verdict::INVALID, ['said' => 'invalid']);
    }
}

class SaysCatchAll implements EmailVerifier
{
    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        FakeEnrichment::$calls[] = 'says-catch-all';

        return new Verification(Verdict::CATCH_ALL, ['said' => 'catch-all']);
    }
}

class SaysNothingUseful implements EmailVerifier
{
    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        FakeEnrichment::$calls[] = 'says-nothing-useful';

        return new Verification(Verdict::UNKNOWN, ['said' => 'nothing useful']);
    }
}

class BrokenVerifier implements EmailVerifier
{
    public function verify(string $email, EnrichmentProvider $provider): Verification
    {
        FakeEnrichment::$calls[] = 'broken-verifier';

        throw new RuntimeException('503 Service Unavailable');
    }
}

/**
 * The DNS interface is shared with the health checks, which read records rather
 * than ask about mail exchangers. Nothing in the waterfall reads a record, so
 * these answer with nothing rather than pretending to a zone they do not have.
 */
abstract class FakeDns implements DnsResolver
{
    public function txtRecords(string $host): array
    {
        return [];
    }

    public function aRecords(string $host): array
    {
        return [];
    }
}

class DomainAlwaysAcceptsMail extends FakeDns
{
    public function hasMailExchanger(string $domain): bool
    {
        return true;
    }
}

class DomainAcceptsNothing extends FakeDns
{
    public function hasMailExchanger(string $domain): bool
    {
        return false;
    }
}

/**
 * Two fakes that differ only in how they bill, because that is a property of
 * the provider now rather than a setting on the row.
 */
class BillsEveryCall implements EmailFinder, PublishesListPrice
{
    public static function listPrice(): ListPrice
    {
        return new ListPrice(perLookup: 0.01, billedOnMiss: true, note: 'A fake that charges whether it finds anything or not.');
    }

    public function supports(Contact $contact): bool
    {
        return true;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'bills-every-call';

        return null;
    }
}

class BillsOnlyForHits implements EmailFinder, PublishesListPrice
{
    public static function listPrice(): ListPrice
    {
        return new ListPrice(perLookup: 0.01, billedOnMiss: false, note: 'A fake that charges only when it returns an address.');
    }

    public function supports(Contact $contact): bool
    {
        return true;
    }

    public function find(Contact $contact, EnrichmentProvider $provider): ?FoundEmail
    {
        FakeEnrichment::$calls[] = 'bills-only-for-hits';

        return null;
    }
}
