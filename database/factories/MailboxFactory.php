<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    protected $model = Mailbox::class;

    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'email' => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'status' => Mailbox::STATUS_ACTIVE,
            'daily_cap' => 40,
            'min_gap_minutes' => 3,
            'max_gap_minutes' => 15,
            'send_window_start' => '09:00',
            'send_window_end' => '17:00',
            'send_timezone' => 'UTC',
            'warmup_enabled' => false,
        ];
    }

    public function warming(): static
    {
        return $this->state(fn () => [
            'warmup_enabled' => true,
            'warmup_started_at' => now(),
            'warmup_start_per_day' => 5,
            'warmup_increment_per_day' => 3,
        ]);
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'google_access_token' => 'test-access-token',
            'google_refresh_token' => 'test-refresh-token',
            'google_token_expires_at' => now()->addHour(),
        ]);
    }

    /** Connected with every scope the app asks for, including Postmaster. */
    public function withPostmasterScope(): static
    {
        return $this->connected()->state(fn () => [
            'google_scopes' => \App\Services\Gmail\GmailClientFactory::SCOPES,
        ]);
    }
}
