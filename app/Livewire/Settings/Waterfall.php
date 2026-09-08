<?php

namespace App\Livewire\Settings;

use App\Models\Contact;
use App\Models\EnrichmentProvider;
use App\Services\Enrichment\BalanceReading;
use App\Services\Enrichment\EnrichmentSwitch;
use App\Services\Sending\EnrollmentActivator;
use App\Services\Sending\VerifiedEmailSwitch;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The email waterfall: who is asked for an address, in what order, and whether
 * anybody is asked at all.
 *
 * Everything here is deliberately live rather than configuration. A provider
 * burning money or returning rubbish has to be stoppable while it is happening,
 * not at the speed of a deploy, and so does the whole chain.
 */
#[Layout('layouts.app')]
class Waterfall extends Component
{
    public ?int $editing = null;

    public string $apiKey = '';

    public ?string $flash = null;

    public function mount(): void
    {
        // Providers are chosen from the ones we have written code for, never
        // invented, so they appear by themselves and sit inert until somebody
        // puts a key against one.
        EnrichmentProvider::syncWithDrivers();
        EnrichmentProvider::renumber();
    }

    public function edit(int $id): void
    {
        $this->editing = $id;
        // Never shown back. These are live billing credentials, and the field
        // starts empty so saving without typing cannot blank an existing key.
        $this->apiKey = '';
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->apiKey = '';
    }

    public function saveKey(): void
    {
        $this->validate(['apiKey' => ['required', 'string', 'max:255']]);

        $provider = EnrichmentProvider::findOrFail($this->editing);
        $provider->forceFill([
            'credentials' => ['api_key' => trim($this->apiKey)],
            // Editing a key clears whatever it was switched off for. The
            // message described a failure somebody has just come to fix.
            'disabled_reason' => null,
            'disabled_at' => null,
        ])->save();

        $this->flash = $provider->name.' now has a key. Switch it on when you are ready.';
        $this->cancel();
    }

    public function toggle(int $id): void
    {
        $provider = EnrichmentProvider::findOrFail($id);

        if (! $provider->enabled && ! $provider->isConfigured()) {
            $this->flash = $provider->name.' has no key yet, so it would fail every call.';

            return;
        }

        $provider->forceFill([
            'enabled' => ! $provider->enabled,
            'disabled_reason' => null,
            'disabled_at' => null,
        ])->save();
    }

    /** Swap this provider with its neighbour, within its own half of the chain. */
    public function move(int $id, string $direction): void
    {
        $provider = EnrichmentProvider::findOrFail($id);

        $neighbour = EnrichmentProvider::query()
            ->where('kind', $provider->kind)
            ->where('position', $direction === 'up' ? '<' : '>', $provider->position)
            ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if (! $neighbour) {
            return;
        }

        [$a, $b] = [$provider->position, $neighbour->position];
        $provider->forceFill(['position' => $b])->saveQuietly();
        $neighbour->forceFill(['position' => $a])->saveQuietly();
    }

    /**
     * Ask every provider with a key what is left on the account.
     *
     * A button rather than something the page does by itself. These are live
     * calls to six other companies, made one after another, and doing them on
     * every render meant opening the page waited on the slowest of them.
     *
     * What comes back is kept until this is pressed again, so the figures on
     * screen carry the date they were read and nobody mistakes a week-old
     * number for the balance now.
     */
    public function checkBalances(): void
    {
        $asked = 0;
        $failed = 0;
        $noKey = 0;

        foreach (EnrichmentProvider::all() as $provider) {
            $reading = $provider->checkBalance();

            if ($reading === null) {
                $noKey++;

                continue;
            }

            $asked++;

            if (! $reading->succeeded()) {
                $failed++;
            }
        }

        // Said in full, so six rows with two figures on them do not read as
        // four providers having failed silently.
        $skipped = $noKey > 0 ? " {$noKey} had no key and were not asked." : '';

        $this->flash = match (true) {
            $asked === 0 => 'No provider has a key yet, so there was nobody to ask.',
            $failed === 0 => "Asked {$asked} provider(s). The figures below are as of now.".$skipped,
            default => "Asked {$asked} provider(s); {$failed} could not be reached. Their rows say why.".$skipped,
        };
    }

    public function orderByPrice(): void
    {
        EnrichmentProvider::orderByPrice();
        $this->flash = 'Reordered cheapest first. That is a starting point, not an answer: check the performance page once there is traffic to judge.';
    }

    public function toggleEnrichment(): void
    {
        if (EnrichmentSwitch::isOn()) {
            EnrichmentSwitch::turnOff();
            $this->flash = 'Enrichment is off. Nothing will be looked up and nothing will be spent. Leads stay pending.';

            return;
        }

        EnrichmentSwitch::turnOn();
        $waiting = EnrichmentSwitch::waiting();
        $this->flash = $waiting > 0
            ? "Enrichment is on. {$waiting} leads are pending, and nothing fetches them by itself: tick them on the Leads page and use \"Check the addresses again\"."
            : 'Enrichment is on.';
    }

    /**
     * Changes the rule, and only the rule.
     *
     * Deliberately does not start anybody already waiting. Those are two
     * different decisions: what to require from now on, and what to do about
     * people who have been queued under the old rule, possibly for weeks.
     * Somebody relaxing the rule for future leads should not discover they
     * have also just released a backlog of two hundred unchecked addresses.
     *
     * Releasing that backlog is its own button, which appears with a count
     * beside it. See startEveryoneWaiting.
     */
    public function toggleVerifiedEmail(): void
    {
        if (VerifiedEmailSwitch::isOn()) {
            VerifiedEmailSwitch::turnOff();
            $this->flash = 'From now on, a lead pushed with an address is emailed without waiting for anything to confirm it. Anybody already waiting stays waiting until you start them.';

            return;
        }

        VerifiedEmailSwitch::turnOn();
        $this->flash = 'Nothing new will be emailed until an address has been confirmed. Anything already sending carries on.';
    }

    /**
     * Starts the people the confirmation rule was holding, and nobody else.
     *
     * The other half of the decision above, asked for separately and on
     * purpose. Only reachable while the rule is off: with it on, these people
     * are waiting for a good reason.
     */
    public function startEveryoneWaiting(EnrollmentActivator $activator): void
    {
        if (VerifiedEmailSwitch::isOn()) {
            $this->flash = 'A confirmed address is still required, so these leads are waiting for a reason. Turn that off first.';

            return;
        }

        $started = $activator->releaseEveryoneNowSendable();

        $this->flash = $started > 0
            ? "{$started} enrollment(s) have started. The first email of each is being drafted now."
            : 'Nothing was waiting on the confirmation rule.';
    }

    /** When the figures on this page were read, or null if nobody has asked. */
    private function balancesCheckedAt(): ?CarbonImmutable
    {
        return EnrichmentProvider::all()
            ->map(fn (EnrichmentProvider $provider): ?BalanceReading => $provider->cachedBalance())
            ->filter()
            ->map(fn (BalanceReading $reading): CarbonImmutable => $reading->checkedAt)
            ->sort()
            ->last();
    }

    public function render()
    {
        return view('livewire.settings.waterfall', [
            'chains' => collect(EnrichmentProvider::kinds())
                ->map(fn (string $label, string $kind): array => [
                    'label' => $label,
                    'providers' => EnrichmentProvider::query()
                        ->where('kind', $kind)
                        ->withCount('lookups')
                        ->inPositionOrder()
                        ->get(),
                ])
                ->all(),
            'enrichmentOn' => EnrichmentSwitch::isOn(),
            'verifiedRequired' => VerifiedEmailSwitch::isOn(),
            // Named on the button that relaxes the rule, so nobody relaxes it
            // without being told how many people it starts.
            'wouldStart' => app(EnrollmentActivator::class)->countWaitingOnConfirmationOnly(),
            'pending' => Contact::query()->where('email_status', Contact::EMAIL_PENDING)->count(),
            // Nothing is asked to draw this: it is the age of what is already
            // on hand, so the page can say how old the figures are before
            // anybody reads one as today's.
            'balancesCheckedAt' => $this->balancesCheckedAt(),
        ]);
    }
}
