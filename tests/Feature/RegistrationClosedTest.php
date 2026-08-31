<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An account on this dashboard can create API keys and approve emails that
 * send from the connected mailboxes, so the sign-up page stays closed unless
 * it is deliberately opened.
 */
class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default(): void
    {
        $this->assertFalse(config('outreach.registration_enabled'));
    }

    public function test_the_sign_up_page_is_not_reachable_when_closed(): void
    {
        config(['outreach.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
    }

    public function test_no_account_can_be_created_while_it_is_closed(): void
    {
        config(['outreach.registration_enabled' => false]);

        // Sign-up runs through the page's Livewire component, which cannot be
        // reached without the page rendering first.
        $this->get('/register')->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_sign_up_link_is_hidden_while_it_is_closed(): void
    {
        config(['outreach.registration_enabled' => false]);

        $this->get('/')->assertOk()->assertDontSee('Register');
    }

    public function test_the_sign_up_page_works_when_opened(): void
    {
        config(['outreach.registration_enabled' => true]);

        $this->get('/register')->assertOk();
    }
}
