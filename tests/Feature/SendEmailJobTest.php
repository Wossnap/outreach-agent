<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchDueEmails;
use App\Jobs\SendEmailJob;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Services\Gmail\GmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendEmailJobTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSending(int $position = 1, int $totalSteps = 1): Message
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id]);

        $steps = collect(range(1, max($totalSteps, $position)))->map(
            fn ($p) => SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => $p])
        );

        return Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $steps[$position - 1]->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'status' => Message::STATUS_SENDING,
            'sending_started_at' => now(),
        ]);
    }

    protected function fakeSender(int $times = 1): void
    {
        $sender = Mockery::mock(GmailSender::class);
        $sender->shouldReceive('send')->times($times)->andReturn([
            'gmail_message_id' => 'gm-123',
            'gmail_thread_id' => 'thread-123',
            'rfc_message_id' => '<rfc@test>',
        ]);
        $this->app->instance(GmailSender::class, $sender);
    }

    public function test_assigns_an_open_token_before_the_send(): void
    {
        $message = $this->makeSending();

        // The token must be on the row when the MIME is built, so the sender
        // has to see it, not just the post-send update.
        $sender = Mockery::mock(GmailSender::class);
        $sender->shouldReceive('send')->once()
            ->withArgs(fn (Message $m) => strlen((string) $m->open_token) === 40)
            ->andReturn(['gmail_message_id' => 'gm-1', 'gmail_thread_id' => 't-1', 'rfc_message_id' => '<r@t>']);
        $this->app->instance(GmailSender::class, $sender);

        (new SendEmailJob($message->id))->handle(app(GmailSender::class));

        $this->assertSame(40, strlen($message->fresh()->open_token));
    }

    public function test_leaves_the_token_empty_when_tracking_is_off(): void
    {
        config(['outreach.open_tracking.enabled' => false]);
        $message = $this->makeSending();
        $this->fakeSender();

        (new SendEmailJob($message->id))->handle(app(GmailSender::class));

        $this->assertNull($message->fresh()->open_token);
    }

    public function test_sends_and_marks_sent_and_completes_single_step_enrollment(): void
    {
        $message = $this->makeSending();
        $this->fakeSender();

        (new SendEmailJob($message->id))->handle(app(GmailSender::class));

        $fresh = $message->fresh();
        $this->assertSame(Message::STATUS_SENT, $fresh->status);
        $this->assertSame('gm-123', $fresh->gmail_message_id);

        $enrollment = $fresh->enrollment;
        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame('thread-123', $enrollment->gmail_thread_id);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->status);
    }

    public function test_enrollment_stays_active_when_more_steps_remain(): void
    {
        $message = $this->makeSending(position: 1, totalSteps: 2);
        $this->fakeSender();

        (new SendEmailJob($message->id))->handle(app(GmailSender::class));

        $this->assertSame(Enrollment::STATUS_ACTIVE, $message->fresh()->enrollment->status);
    }

    public function test_cancels_when_enrollment_no_longer_active(): void
    {
        $message = $this->makeSending();
        $message->enrollment->update(['status' => Enrollment::STATUS_STOPPED_REPLY]);
        $this->fakeSender(times: 0);

        (new SendEmailJob($message->id))->handle(app(GmailSender::class));

        $this->assertSame(Message::STATUS_CANCELLED, $message->fresh()->status);
    }

    public function test_cancels_when_contact_suppressed_after_scheduling(): void
    {
        $message = $this->makeSending();
        Suppression::suppress($message->contact->email, Suppression::REASON_UNSUBSCRIBED);
        $this->fakeSender(times: 0);

        (new SendEmailJob($message->id))->handle(app(GmailSender::class));

        $this->assertSame(Message::STATUS_CANCELLED, $message->fresh()->status);
    }

    public function test_dispatch_command_claims_atomically(): void
    {
        Queue::fake();
        $message = $this->makeSending();
        $message->update(['status' => Message::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()]);

        $this->artisan(DispatchDueEmails::class)->assertSuccessful();
        $this->artisan(DispatchDueEmails::class)->assertSuccessful();

        // Two runs, one claim: the second run sees status=sending and skips.
        Queue::assertPushed(SendEmailJob::class, 1);
        $this->assertSame(Message::STATUS_SENDING, $message->fresh()->status);
    }

    public function test_dispatch_skips_paused_mailbox_but_leaves_scheduled(): void
    {
        Queue::fake();
        $message = $this->makeSending();
        $message->update(['status' => Message::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()]);
        $message->mailbox->pause('manual');

        $this->artisan(DispatchDueEmails::class)->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(Message::STATUS_SCHEDULED, $message->fresh()->status);
    }

    public function test_dispatch_ignores_future_messages(): void
    {
        Queue::fake();
        $message = $this->makeSending();
        $message->update(['status' => Message::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()]);

        $this->artisan(DispatchDueEmails::class)->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
