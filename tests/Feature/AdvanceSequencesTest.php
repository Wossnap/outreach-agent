<?php

namespace Tests\Feature;

use App\Console\Commands\AdvanceSequences;
use App\Console\Commands\ReconcileStuckMessages;
use App\Jobs\DraftEmailJob;
use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SequenceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdvanceSequencesTest extends TestCase
{
    use RefreshDatabase;

    protected function makeEnrollmentWithSentStep(int $delayDays = 3): Enrollment
    {
        $enrollment = Enrollment::factory()->create(['current_step' => 1]);
        $step1 = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        SequenceStep::factory()->create([
            'automation_id' => $enrollment->automation_id,
            'position' => 2,
            'delay_days' => $delayDays,
        ]);

        Message::factory()->sent()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step1->id,
            'contact_id' => $enrollment->contact_id,
            'sent_at' => now()->subDays(4),
        ]);

        return $enrollment;
    }

    public function test_advances_when_delay_elapsed(): void
    {
        Queue::fake();
        $enrollment = $this->makeEnrollmentWithSentStep(delayDays: 3);

        $this->artisan(AdvanceSequences::class)->assertSuccessful();

        Queue::assertPushed(DraftEmailJob::class, fn ($job) => $job->enrollmentId === $enrollment->id && $job->stepPosition === 2);
    }

    public function test_waits_for_delay(): void
    {
        Queue::fake();
        $this->makeEnrollmentWithSentStep(delayDays: 10);

        $this->artisan(AdvanceSequences::class)->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_skips_stopped_enrollments(): void
    {
        Queue::fake();
        $enrollment = $this->makeEnrollmentWithSentStep(delayDays: 3);
        $enrollment->update(['status' => Enrollment::STATUS_STOPPED_REPLY]);

        $this->artisan(AdvanceSequences::class)->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_does_not_double_draft_existing_next_step(): void
    {
        Queue::fake();
        $enrollment = $this->makeEnrollmentWithSentStep(delayDays: 3);
        $step2 = SequenceStep::query()->where('automation_id', $enrollment->automation_id)->where('position', 2)->first();
        Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step2->id,
            'contact_id' => $enrollment->contact_id,
        ]);

        $this->artisan(AdvanceSequences::class)->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_reconciler_heals_sent_and_fails_unsent(): void
    {
        $reachedGmail = Message::factory()->scheduled()->create([
            'status' => Message::STATUS_SENDING,
            'sending_started_at' => now()->subMinutes(30),
            'gmail_message_id' => 'gm-1',
        ]);

        $neverLeft = Message::factory()->scheduled()->create([
            'status' => Message::STATUS_SENDING,
            'sending_started_at' => now()->subMinutes(30),
            'gmail_message_id' => null,
        ]);

        $fresh = Message::factory()->scheduled()->create([
            'status' => Message::STATUS_SENDING,
            'sending_started_at' => now()->subMinutes(2),
        ]);

        $this->artisan(ReconcileStuckMessages::class)->assertSuccessful();

        $this->assertSame(Message::STATUS_SENT, $reachedGmail->fresh()->status);
        $this->assertSame(Message::STATUS_FAILED, $neverLeft->fresh()->status);
        $this->assertSame(Message::STATUS_SENDING, $fresh->fresh()->status);
        $this->assertTrue(ActivityLog::query()->where('event', 'send_stuck')->where('retryable', true)->exists());
    }
}
