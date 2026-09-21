<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\PostmasterStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/health')->assertRedirect('/login');
    }

    public function test_shows_every_domain_check(): void
    {
        $this->actingAs(User::factory()->create());

        $domain = Domain::factory()->create([
            'name' => 'shown.test',
            'spf_status' => 'ok',
            'dkim_status' => 'ok',
            'dmarc_status' => 'missing',
        ]);
        Mailbox::factory()->connected()->create(['domain_id' => $domain->id]);

        $this->get('/health')
            ->assertOk()
            ->assertSee('shown.test')
            ->assertSee('DMARC')
            ->assertSee('missing')
            ->assertSee('Open (7d)')
            ->assertSee('Spam rate (Gmail)');
    }

    public function test_shows_gmail_spam_rate_and_says_when_it_cannot_be_read(): void
    {
        $this->actingAs(User::factory()->create());

        $rated = Domain::factory()->create(['name' => 'rated.test', 'postmaster_verification' => 'VERIFIED']);
        Mailbox::factory()->withPostmasterScope()->create(['domain_id' => $rated->id]);
        PostmasterStat::factory()->create(['domain_id' => $rated->id, 'spam_rate' => 0.0042]);

        Domain::factory()->create(['name' => 'broken.test', 'postmaster_error' => 'Not registered or not verified in Postmaster Tools']);

        $this->get('/health')
            ->assertOk()
            ->assertSee('0.42%')
            ->assertSee('verified')
            ->assertSee('Not registered or not verified')
            // A mailbox holds the scope, so no nagging to reconnect.
            ->assertDontSee('Reconnect a mailbox');
    }

    public function test_asks_for_a_reconnect_when_no_mailbox_has_the_postmaster_scope(): void
    {
        $this->actingAs(User::factory()->create());
        Mailbox::factory()->connected()->create();

        $this->get('/health')->assertOk()->assertSee('Reconnect a mailbox');
    }
}
