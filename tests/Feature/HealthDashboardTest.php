<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
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
            ->assertSee('missing');
    }
}
