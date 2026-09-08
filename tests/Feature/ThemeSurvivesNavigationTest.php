<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The dark class has to be rendered by the server, not only set by the browser.
 *
 * wire:navigate fetches the next page and copies its <html> attributes over the
 * current ones, dropping any the new page does not carry. If the class were
 * only ever added by JavaScript, every navigation would strip it and the page
 * would revert to light.
 */
class ThemeSurvivesNavigationTest extends TestCase
{
    public function test_a_dark_choice_is_rendered_into_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->withUnencryptedCookie('theme_resolved', 'dark')
            ->get('/leads')
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('class="dark"', false);
    }

    public function test_a_light_choice_leaves_the_class_off(): void
    {
        $this->actingAs(User::factory()->create())
            ->withUnencryptedCookie('theme_resolved', 'light')
            ->get('/leads')
            ->assertOk()
            ->assertDontSee('class="dark"', false);
    }

    public function test_the_login_page_honours_it_too(): void
    {
        $this->withUnencryptedCookie('theme_resolved', 'dark')
            ->get('/login')
            ->assertOk()
            ->assertSee('class="dark"', false);
    }

    public function test_an_encrypted_cookie_is_not_mistaken_for_a_choice(): void
    {
        // JavaScript writes this one in the clear, so it is exempt from
        // encryption. Anything arriving encrypted is not a theme choice.
        $this->actingAs(User::factory()->create())
            ->withCookie('theme_resolved', 'dark')
            ->get('/leads')
            ->assertDontSee('class="dark"', false);
    }
}
