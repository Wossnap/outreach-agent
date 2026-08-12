<?php

namespace Tests\Unit;

use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Services\Sending\SendScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SendSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function freeze(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function makePending(Mailbox $mailbox): Message
    {
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        return Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
        ]);
    }

    public function test_schedules_within_window_with_gap_after_now(): void
    {
        $this->freeze('2026-08-12 12:00:00'); // Wednesday, inside 09-17 UTC window
        $mailbox = Mailbox::factory()->connected()->create(['min_gap_minutes' => 3, 'max_gap_minutes' => 15]);
        $message = $this->makePending($mailbox);

        $slot = app(SendScheduler::class)->schedule($message);

        $this->assertNotNull($slot);
        $this->assertTrue($slot->between(now()->addMinutes(3), now()->addMinutes(15)));
        $this->assertSame(Message::STATUS_SCHEDULED, $message->fresh()->status);
    }

    public function test_consecutive_slots_have_randomized_compounding_gaps(): void
    {
        $this->freeze('2026-08-12 09:30:00');
        $mailbox = Mailbox::factory()->connected()->create(['min_gap_minutes' => 3, 'max_gap_minutes' => 15]);

        $slots = collect(range(1, 5))->map(fn () => app(SendScheduler::class)->schedule($this->makePending($mailbox)));

        // Slots strictly increase and each gap respects min..max bounds.
        $previous = null;
        $gaps = [];
        foreach ($slots as $slot) {
            if ($previous) {
                $gap = $previous->diffInSeconds($slot);
                $this->assertGreaterThanOrEqual(3 * 60, $gap);
                $this->assertLessThanOrEqual(15 * 60, $gap);
                $gaps[] = $gap;
            }
            $previous = $slot;
        }

        // Second-granular jitter: it would be vanishingly unlikely for all
        // gaps to land on exact minute boundaries.
        $this->assertNotEmpty(array_filter($gaps, fn ($g) => $g % 60 !== 0));
    }

    public function test_before_window_pushes_to_window_start_with_jitter(): void
    {
        $this->freeze('2026-08-12 03:00:00'); // before 09:00 window
        $mailbox = Mailbox::factory()->connected()->create();
        $message = $this->makePending($mailbox);

        $slot = app(SendScheduler::class)->schedule($message);

        $this->assertSame('2026-08-12', $slot->toDateString());
        $this->assertTrue($slot->between(
            CarbonImmutable::parse('2026-08-12 09:00:00', 'UTC'),
            CarbonImmutable::parse('2026-08-12 09:45:00', 'UTC'),
        ));
    }

    public function test_after_window_rolls_to_next_day(): void
    {
        $this->freeze('2026-08-12 20:00:00'); // after 17:00 window, Wednesday
        $mailbox = Mailbox::factory()->connected()->create();
        $message = $this->makePending($mailbox);

        $slot = app(SendScheduler::class)->schedule($message);

        $this->assertSame('2026-08-13', $slot->toDateString());
    }

    public function test_weekend_skipped_when_weekends_disabled(): void
    {
        $this->freeze('2026-08-14 20:00:00'); // Friday evening
        $mailbox = Mailbox::factory()->connected()->create(['send_weekends' => false]);
        $message = $this->makePending($mailbox);

        $slot = app(SendScheduler::class)->schedule($message);

        $this->assertSame('2026-08-17', $slot->toDateString()); // Monday
    }

    public function test_daily_cap_rolls_to_next_day(): void
    {
        $this->freeze('2026-08-12 10:00:00');
        $mailbox = Mailbox::factory()->connected()->create(['daily_cap' => 2]);

        // Fill today's cap with two already-scheduled sends.
        foreach (range(1, 2) as $i) {
            $this->makePending($mailbox)->update([
                'status' => Message::STATUS_SCHEDULED,
                'scheduled_at' => now()->addMinutes($i * 10),
            ]);
        }

        $slot = app(SendScheduler::class)->schedule($this->makePending($mailbox));

        $this->assertSame('2026-08-13', $slot->toDateString());
    }

    public function test_warmup_cap_limits_day(): void
    {
        $this->freeze('2026-08-12 10:00:00');
        $mailbox = Mailbox::factory()->connected()->create([
            'daily_cap' => 40,
            'warmup_enabled' => true,
            'warmup_started_at' => now(),
            'warmup_start_per_day' => 1,
            'warmup_increment_per_day' => 1,
        ]);

        // Effective cap today = 1; one message fills it.
        $this->makePending($mailbox)->update([
            'status' => Message::STATUS_SCHEDULED,
            'scheduled_at' => now()->addMinutes(10),
        ]);

        $slot = app(SendScheduler::class)->schedule($this->makePending($mailbox));

        $this->assertNotSame('2026-08-12', $slot->toDateString());
    }

    public function test_respects_mailbox_timezone(): void
    {
        $this->freeze('2026-08-12 14:00:00'); // 00:00 Aug 13 in Sydney (UTC+10)
        $mailbox = Mailbox::factory()->connected()->create(['send_timezone' => 'Australia/Sydney']);
        $message = $this->makePending($mailbox);

        $slot = app(SendScheduler::class)->schedule($message);
        $local = $slot->setTimezone('Australia/Sydney');

        $this->assertSame('2026-08-13', $local->toDateString());
        $this->assertGreaterThanOrEqual(9, $local->hour);
        $this->assertLessThanOrEqual(17, $local->hour);
    }

    public function test_returns_null_and_stays_approved_without_mailbox(): void
    {
        $this->freeze('2026-08-12 12:00:00');
        $enrollment = Enrollment::factory()->create(['mailbox_id' => null]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        $message = Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'status' => Message::STATUS_APPROVED,
        ]);

        $slot = app(SendScheduler::class)->schedule($message);

        $this->assertNull($slot);
        $this->assertSame(Message::STATUS_APPROVED, $message->fresh()->status);
    }

    public function test_reassigns_mailbox_when_pinned_one_is_paused(): void
    {
        $this->freeze('2026-08-12 12:00:00');
        $paused = Mailbox::factory()->connected()->create(['status' => Mailbox::STATUS_PAUSED]);
        $healthy = Mailbox::factory()->connected()->create();
        $message = $this->makePending($paused);

        $slot = app(SendScheduler::class)->schedule($message);

        $this->assertNotNull($slot);
        $this->assertSame($healthy->id, $message->fresh()->mailbox_id);
        $this->assertSame($healthy->id, $message->enrollment->fresh()->mailbox_id);
    }
}
