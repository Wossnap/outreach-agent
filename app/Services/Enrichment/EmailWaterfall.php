<?php

namespace App\Services\Enrichment;

use App\Models\Contact;
use App\Models\EmailLookup;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\Contracts\EmailFinder;
use App\Services\Enrichment\Contracts\EmailVerifier;
use App\Services\Sending\EnrollmentActivator;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Finds an address for a lead and decides whether it can receive mail.
 *
 * Cheapest first. The free checks run before anything is paid for, finders are
 * tried in order and stop at the first hit, and verification stops at the first
 * provider willing to give a straight answer.
 *
 * Nothing here decides which providers exist or in what order: that is data, so
 * a provider burning money or returning rubbish can be switched off while it is
 * happening rather than at the speed of a deploy.
 */
class EmailWaterfall
{
    /**
     * A provider whose last few calls all failed is switched off.
     *
     * Otherwise every lead queued behind it fails on its way to a provider that
     * would have worked, and the cost of one expired API key is every lookup
     * after it.
     */
    private const FAILURES_BEFORE_DISABLING = 5;

    public function __construct(
        private readonly FreeGate $gate,
        private readonly EnrollmentActivator $activator,
    ) {}

    /**
     * @param  bool  $askAgain  ask every provider afresh, including ones that
     *                          have already answered for this lead. Only a
     *                          person pressing "check the address again" wants
     *                          that; a retry should not pay twice for an answer
     *                          it already has.
     */
    public function run(Contact $contact, bool $askAgain = false): void
    {
        // Already settled. Running again would pay for an answer we have.
        if ($contact->isEmailResolved()) {
            return;
        }

        /*
         * Checked here as well as before the job is queued, so a job that was
         * already on the queue when somebody switched this off does not go on
         * spending. The lead keeps whatever status it has, which is pending.
         */
        if (EnrichmentSwitch::isOff()) {
            return;
        }

        $answered = $askAgain ? [] : $this->providersThatAnswered($contact);
        $anyFailed = false;

        if (blank($contact->email)) {
            /*
             * With no finder configured there is nobody to ask, and saying "not
             * found" would claim we looked. The lead waits to retry so it is
             * picked up once a finder exists, rather than being written off by
             * a gap in the setup.
             */
            if ($this->readyProviders(EnrichmentProvider::KIND_FIND)->isEmpty()) {
                $this->leaveUnsettled($contact);

                return;
            }

            $this->find($contact, $answered, $anyFailed);

            if (blank($contact->fresh()->email)) {
                /*
                 * Not found only when every finder actually answered. One that
                 * failed, out of credits or over its limit, never looked, and
                 * "not found" would write the lead off for good: settled leads
                 * are never asked again, and not-found ones are hidden from the
                 * Leads page by default.
                 *
                 * The same goes for a finder that is off because it kept
                 * failing: it is waiting for a top-up, not out of the chain.
                 */
                $anyFailed || $this->stillToHearFrom($contact, EnrichmentProvider::KIND_FIND)
                    ? $this->leaveUnsettled($contact)
                    : $this->settle($contact, Contact::EMAIL_NOT_FOUND);

                return;
            }
        }

        $this->verify($contact->refresh(), $answered);
    }

    /**
     * Providers that have already given this lead a real answer.
     *
     * A finder that said "nothing" will say nothing again, and a verifier that
     * could not commit will not commit now, so asking either twice is paying
     * for an answer we have. A call that failed is not an answer: the provider
     * never looked, and it is exactly the one worth asking again.
     *
     * @return array<int, int> provider ids
     */
    private function providersThatAnswered(Contact $contact): array
    {
        return EmailLookup::query()
            ->where('contact_id', $contact->id)
            ->whereNotNull('enrichment_provider_id')
            ->where('result', '!=', EmailLookup::RESULT_ERROR)
            ->distinct()
            ->pluck('enrichment_provider_id')
            ->all();
    }

    /**
     * Waiting to retry: looked up, not finished, nothing decided.
     *
     * Waiting is what the retry looks for, so a lead left here is picked up
     * again as soon as a provider can take it. Pending would say nobody has
     * looked yet, and finding or verifying that a lookup is under way when
     * none is.
     */
    private function leaveUnsettled(Contact $contact): void
    {
        $contact->update(['email_status' => Contact::EMAIL_WAITING]);
    }

    /**
     * Whether a provider the waterfall switched off has yet to answer for this
     * lead.
     *
     * A provider switched off for failing five times in a row is out of credit
     * or over its limit, and comes back by itself once topped up. Until then it
     * has not looked, so settling the lead without it would write the lead off
     * on the word of the providers that happened to be left. One somebody
     * switched off by hand carries no reason and is genuinely out of the chain.
     */
    private function stillToHearFrom(Contact $contact, string $kind): bool
    {
        $answered = $this->providersThatAnswered($contact);

        return EnrichmentProvider::query()
            ->where('kind', $kind)
            ->where('enabled', false)
            ->whereNotNull('disabled_reason')
            ->get()
            ->filter(fn (EnrichmentProvider $provider): bool => $provider->isConfigured()
                && ! in_array($provider->id, $answered, true))
            ->isNotEmpty();
    }

    /**
     * @param  array<int, int>  $answered  providers not to ask again
     * @param  bool  $anyFailed  set when a finder failed rather than answered
     * @return FoundEmail|null the address a finder returned, if one did
     */
    private function find(Contact $contact, array $answered, bool &$anyFailed): ?FoundEmail
    {
        $contact->update(['email_status' => Contact::EMAIL_FINDING]);

        foreach ($this->readyProviders(EnrichmentProvider::KIND_FIND) as $provider) {
            if (in_array($provider->id, $answered, true)) {
                continue;
            }

            $found = $this->attemptFind($contact, $provider);

            if ($found === false) {
                $anyFailed = true;

                continue;
            }

            if ($found instanceof FoundEmail) {
                $contact->forceFill([
                    'email' => $found->email,
                    'email_provider' => $provider->name,
                ]);

                /*
                 * The rest of what the provider said is not copied here.
                 *
                 * Every call already writes an email_lookups row carrying the
                 * full response, timestamped and priced, which is a better
                 * record than a bag on the contact that nothing read. Keeping
                 * both meant the drafter had to be taught which namespaces were
                 * providers so it could skip them, which tied the prompt to the
                 * list of drivers in config.
                 */

                // Filled in only where we have nothing. A provider's guess at
                // a job title should not overwrite one somebody sent us.
                foreach (['job_title', 'company'] as $field) {
                    if (blank($contact->{$field}) && filled($found->extra[$field] ?? null)) {
                        $contact->{$field} = $found->extra[$field];
                    }
                }

                if (blank($contact->profile_url) && filled($found->extra['linkedin_url'] ?? null)) {
                    $contact->profile_url = $found->extra['linkedin_url'];
                }

                $contact->save();

                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $answered  providers not to ask again
     */
    private function verify(Contact $contact, array $answered): void
    {
        /*
         * Nobody to ask, so nothing is claimed. Mirrors what the find half
         * already does. The address a finder just returned is kept, and the
         * lead waits to retry so it is checked once a verifier is back.
         *
         * An empty verify chain is a gap in the setup, not a verdict about the
         * address. Settling as risky would count as settled, and the lead would
         * never be looked at again.
         */
        if ($this->readyProviders(EnrichmentProvider::KIND_VERIFY)->isEmpty()) {
            $this->leaveUnsettled($contact);

            return;
        }

        $contact->update(['email_status' => Contact::EMAIL_VERIFYING]);

        // Free, and it removes the addresses no provider could have delivered
        // to either. Recorded like any other step so the reason a lead was
        // rejected is always on its record.
        if (! $this->gate->passes($contact->email)) {
            $this->recordFreeGate($contact);
            $this->settle($contact, Contact::EMAIL_INVALID);

            return;
        }

        $anyFailed = false;

        foreach ($this->readyProviders(EnrichmentProvider::KIND_VERIFY) as $provider) {
            if (in_array($provider->id, $answered, true)) {
                continue;
            }

            $verdict = $this->attemptVerify($contact, $provider);

            if ($verdict === null) {
                $anyFailed = true;

                continue;
            }

            if ($verdict->isConclusive()) {
                $this->settle($contact, match ($verdict) {
                    Verdict::VALID => Contact::EMAIL_VALID,
                    Verdict::INVALID => Contact::EMAIL_INVALID,
                    // A catch-all domain accepts every address it is offered,
                    // so a yes here means nothing about this mailbox.
                    Verdict::CATCH_ALL => Contact::EMAIL_RISKY,
                    default => Contact::EMAIL_RISKY,
                });

                return;
            }
        }

        /*
         * A verifier that failed never looked, and the one that failed may be
         * the specialist that settles catch-alls. Risky is settled for good, so
         * the lead waits instead, and the retry asks only the ones that did not
         * answer. Likewise when that specialist is off because it kept failing.
         */
        if ($anyFailed || $this->stillToHearFrom($contact, EnrichmentProvider::KIND_VERIFY)) {
            $this->leaveUnsettled($contact);

            return;
        }

        /*
         * Everybody was asked and nobody would commit. Risky rather than valid:
         * an address only becomes sendable when something has actually
         * confirmed it, and the absence of a no is not a yes.
         *
         * This means "we looked and could not tell", which is why the case
         * where there was nobody to ask is turned away at the top instead.
         */
        $this->settle($contact, Contact::EMAIL_RISKY);
    }

    /**
     * The providers that can actually be called, in order.
     *
     * A provider with no key is skipped rather than tried. Calling it would
     * fail every time and eventually switch it off for failing, which reads as
     * a broken provider when the truth is an empty field.
     *
     * @return Collection<int, EnrichmentProvider>
     */
    private function readyProviders(string $kind): Collection
    {
        // The same call the settings page makes to show what will happen, so
        // the page and the run cannot disagree.
        return EnrichmentProvider::chainFor($kind);
    }

    /**
     * @return FoundEmail|false|null the address, false when the call failed, or
     *                               null when the provider had nothing or no
     *                               way in for this lead
     */
    private function attemptFind(Contact $contact, EnrichmentProvider $provider): FoundEmail|false|null
    {
        $startedAt = hrtime(true);

        try {
            /** @var EmailFinder $driver */
            $driver = $provider->driver();

            // Nothing was asked of it, so nothing is recorded and nothing is
            // charged: this provider simply has no way in for this lead.
            if (! $driver->supports($contact)) {
                return null;
            }

            $found = $driver->find($contact, $provider);
        } catch (Throwable $e) {
            $this->recordFailure($contact, $provider, $startedAt, $e);

            return false;
        }

        $this->record(
            contact: $contact,
            provider: $provider,
            result: $found ? EmailLookup::RESULT_FOUND : EmailLookup::RESULT_NOTHING,
            hit: (bool) $found,
            startedAt: $startedAt,
            detail: $found?->detail ?? [],
        );

        return $found;
    }

    /** @return Verdict|null the verdict, or null when the call failed */
    private function attemptVerify(Contact $contact, EnrichmentProvider $provider): ?Verdict
    {
        $startedAt = hrtime(true);

        try {
            /** @var EmailVerifier $driver */
            $driver = $provider->driver();
            $verification = $driver->verify($contact->email, $provider);
        } catch (Throwable $e) {
            $this->recordFailure($contact, $provider, $startedAt, $e);

            return null;
        }

        $this->record(
            contact: $contact,
            provider: $provider,
            result: $verification->verdict->value,
            // A verdict either way is worth paying for. Being told an address
            // is dead is exactly what stops the send.
            hit: $verification->verdict->isConclusive(),
            startedAt: $startedAt,
            // What the provider reported, kept so a verdict somebody disputes
            // can actually be looked at.
            detail: $verification->detail,
        );

        return $verification->verdict;
    }

    private function record(
        Contact $contact,
        EnrichmentProvider $provider,
        string $result,
        bool $hit,
        int|float $startedAt,
        array $detail = [],
    ): void {
        EmailLookup::create([
            'contact_id' => $contact->id,
            'enrichment_provider_id' => $provider->id,
            'provider_name' => $provider->name,
            'driver' => $provider->driver,
            'kind' => $provider->kind,
            'result' => $result,
            // What was actually charged, not what is listed. A provider that
            // bills only for a hit costs nothing on a miss, and averaging the
            // list price would overstate it.
            'cost' => $hit || $provider->chargesOnMiss() ? $provider->costPerLookup() : 0,
            'duration_ms' => $this->millisecondsSince($startedAt),
            'detail' => $detail ?: null,
        ]);
    }

    private function recordFailure(Contact $contact, EnrichmentProvider $provider, int|float $startedAt, Throwable $e): void
    {
        EmailLookup::create([
            'contact_id' => $contact->id,
            'enrichment_provider_id' => $provider->id,
            'provider_name' => $provider->name,
            'driver' => $provider->driver,
            'kind' => $provider->kind,
            'result' => EmailLookup::RESULT_ERROR,
            // A call that failed was not a lookup, whatever the provider's
            // billing says: we have no answer to have paid for.
            'cost' => 0,
            'duration_ms' => $this->millisecondsSince($startedAt),
            'detail' => ['error' => mb_substr($e->getMessage(), 0, 500)],
        ]);

        $this->disableIfItKeepsFailing($provider);
    }

    private function disableIfItKeepsFailing(EnrichmentProvider $provider): void
    {
        $recent = $provider->lookups()
            ->latest('id')
            ->take(self::FAILURES_BEFORE_DISABLING)
            ->pluck('result');

        if ($recent->count() < self::FAILURES_BEFORE_DISABLING) {
            return;
        }

        if ($recent->every(fn (string $result): bool => $result === EmailLookup::RESULT_ERROR)) {
            $provider->disableBecause(
                self::FAILURES_BEFORE_DISABLING.' calls in a row failed. Check the key and the account balance.'
            );
        }
    }

    private function recordFreeGate(Contact $contact): void
    {
        EmailLookup::create([
            'contact_id' => $contact->id,
            'provider_name' => 'Syntax and DNS',
            'driver' => EmailLookup::DRIVER_FREE_GATE,
            'kind' => EnrichmentProvider::KIND_VERIFY,
            'result' => EmailLookup::RESULT_INVALID,
            'cost' => 0,
            'detail' => ['reason' => $this->gate->looksLikeAnAddress($contact->email)
                ? 'The domain publishes nowhere to deliver mail.'
                : 'Not a valid email address.'],
        ]);
    }

    /**
     * Record the verdict, and release anyone who was waiting on it.
     *
     * This is the join between the two halves of the system. A contact pushed
     * with tags but no confirmed address is enrolled and held at
     * `waiting_email`; the moment an address is confirmed, those enrollments
     * pick a mailbox and draft their first email exactly as they would have
     * done had the address been known when they were pushed.
     *
     * Only a confirmed address does that. Risky, invalid and not-found all
     * leave the enrollment waiting, which is where somebody can see it and
     * decide what to do.
     */
    private function settle(Contact $contact, string $status): void
    {
        $contact->update(['email_status' => $status, 'email_checked_at' => now()]);

        if ($status === Contact::EMAIL_VALID) {
            $this->activator->activateWaiting($contact);
        }
    }

    private function millisecondsSince(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
