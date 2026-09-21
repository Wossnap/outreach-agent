<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Automation;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\PostmasterStat;
use App\Models\Reply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    protected function sentFor(Automation $automation, Mailbox $mailbox, array $attributes = []): Message
    {
        $enrollment = Enrollment::factory()->create(['automation_id' => $automation->id, 'mailbox_id' => $mailbox->id]);

        return Message::factory()->sent()->create(array_merge([
            'enrollment_id' => $enrollment->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'sent_at' => now()->subDays(2),
        ], $attributes));
    }

    public function test_counts_sent_opened_replied_and_bounced_in_the_window(): void
    {
        $this->actingAs(User::factory()->create());
        $mailbox = Mailbox::factory()->connected()->create();
        $automation = Automation::factory()->create(['name' => 'Garden bloggers']);

        $opened = $this->sentFor($automation, $mailbox, ['open_token' => Message::generateOpenToken(), 'first_opened_at' => now()->subDay(), 'open_count' => 3]);
        $this->sentFor($automation, $mailbox, ['open_token' => Message::generateOpenToken()]);
        $this->sentFor($automation, $mailbox);
        // Old enough to fall outside a 7-day window.
        $this->sentFor($automation, $mailbox, ['sent_at' => now()->subDays(40)]);

        Reply::factory()->create(['mailbox_id' => $mailbox->id, 'enrollment_id' => $opened->enrollment_id, 'classification' => Reply::CLASS_REPLY, 'received_at' => now()->subDay()]);
        Reply::factory()->create(['mailbox_id' => $mailbox->id, 'classification' => Reply::CLASS_BOUNCE, 'received_at' => now()->subDay()]);

        $component = Livewire::test(Dashboard::class)->set('window', '7');

        $totals = $component->viewData('totals');
        $this->assertSame(3, $totals['sent']);
        $this->assertSame(2, $totals['tracked']);
        $this->assertSame(1, $totals['opened']);
        $this->assertSame(0.5, $totals['open_rate']);
        $this->assertSame(1, $totals['replied']);
        $this->assertSame(1, $totals['bounced']);

        $component->assertSee('Garden bloggers')->assertSee('of 2 tracked');

        $this->assertSame(4, Livewire::test(Dashboard::class)->set('window', 'all')->viewData('totals')['sent']);
    }

    public function test_days_are_bucketed_in_the_display_timezone(): void
    {
        $this->actingAs(User::factory()->create());
        config(['outreach.timezone' => 'Pacific/Auckland']);

        $mailbox = Mailbox::factory()->connected()->create();
        $automation = Automation::factory()->create();

        // Late in the UTC day, which is already the next day in Auckland.
        $sentAt = CarbonImmutable::now('UTC')->subDay()->setTime(23, 30);
        $this->sentFor($automation, $mailbox, ['sent_at' => $sentAt]);

        $daily = collect(Livewire::test(Dashboard::class)->set('window', '7')->viewData('daily'))->keyBy('day');

        $this->assertSame(1, $daily[$sentAt->setTimezone('Pacific/Auckland')->toDateString()]['sent']);
        $this->assertSame(0, $daily[$sentAt->toDateString()]['sent']);
    }

    public function test_the_spam_tile_names_the_worst_domain_or_says_there_is_no_data(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)->assertSee('no Postmaster data yet');

        $calm = Domain::factory()->create(['name' => 'calm.test']);
        $noisy = Domain::factory()->create(['name' => 'noisy.test']);
        PostmasterStat::factory()->create(['domain_id' => $calm->id, 'spam_rate' => 0.0005]);
        PostmasterStat::factory()->create(['domain_id' => $noisy->id, 'spam_rate' => 0.0042]);
        Domain::factory()->create(['name' => 'silent.test', 'postmaster_error' => 'Not registered or not verified in Postmaster Tools']);

        Livewire::test(Dashboard::class)
            ->assertSee('0.42%')
            ->assertSee('noisy.test')
            ->assertSee('0.05%')
            ->assertSee('Not registered or not verified');
    }

    public function test_an_unknown_window_falls_back_to_thirty_days(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)->set('window', 'forever')->assertSet('window', '30');
    }
}
