<?php

namespace Tests\Unit;

use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxWarmupTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_cap_without_warmup_is_daily_cap(): void
    {
        $mailbox = Mailbox::factory()->create(['daily_cap' => 40, 'warmup_enabled' => false]);

        $this->assertSame(40, $mailbox->effectiveDailyCap());
    }

    public function test_effective_cap_ramps_from_warmup_start(): void
    {
        $mailbox = Mailbox::factory()->create([
            'daily_cap' => 40,
            'warmup_enabled' => true,
            'warmup_started_at' => now()->subDays(4),
            'warmup_start_per_day' => 5,
            'warmup_increment_per_day' => 3,
        ]);

        // Day 4 of warmup: 5 + 3*4 = 17
        $this->assertSame(17, $mailbox->effectiveDailyCap());
        $this->assertTrue($mailbox->isWarming());
    }

    public function test_effective_cap_never_exceeds_daily_cap(): void
    {
        $mailbox = Mailbox::factory()->create([
            'daily_cap' => 40,
            'warmup_enabled' => true,
            'warmup_started_at' => now()->subDays(100),
            'warmup_start_per_day' => 5,
            'warmup_increment_per_day' => 3,
        ]);

        $this->assertSame(40, $mailbox->effectiveDailyCap());
        $this->assertFalse($mailbox->isWarming());
    }
}
