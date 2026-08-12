<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Livewire\Activity\Index;
use App\Livewire\Settings\ApiKeys;
use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_retry_draft_failed_redispatches_job(): void
    {
        Queue::fake();
        $enrollment = Enrollment::factory()->create();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        $message = Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'status' => Message::STATUS_DRAFT_FAILED,
        ]);

        $log = ActivityLog::record('draft_failed', 'failed', ActivityLog::LEVEL_ERROR, $message, ['enrollment_id' => $enrollment->id, 'step_position' => 1], true);

        Livewire::test(Index::class)->call('retry', $log->id);

        Queue::assertPushed(DraftEmailJob::class, fn ($job) => $job->enrollmentId === $enrollment->id);
        $this->assertNotNull($log->fresh()->retried_at);
        $this->assertSame(Message::STATUS_DRAFTING, $message->fresh()->status);
    }

    public function test_retry_send_failed_reschedules(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        $message = Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'status' => Message::STATUS_FAILED,
        ]);

        $log = ActivityLog::record('send_failed', 'failed', ActivityLog::LEVEL_ERROR, $message, [], true);

        Livewire::test(Index::class)->call('retry', $log->id);

        $fresh = $message->fresh();
        $this->assertSame(Message::STATUS_SCHEDULED, $fresh->status);
        $this->assertNotNull($fresh->scheduled_at);
        $this->assertNotNull($log->fresh()->retried_at);
    }

    public function test_retry_send_refuses_for_stopped_enrollment(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id, 'status' => Enrollment::STATUS_STOPPED_REPLY]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        $message = Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'status' => Message::STATUS_FAILED,
        ]);

        $log = ActivityLog::record('send_failed', 'failed', ActivityLog::LEVEL_ERROR, $message, [], true);

        Livewire::test(Index::class)->call('retry', $log->id);

        $this->assertSame(Message::STATUS_FAILED, $message->fresh()->status);
        $this->assertNull($log->fresh()->retried_at);
    }

    public function test_api_key_lifecycle(): void
    {
        $component = Livewire::test(ApiKeys::class)
            ->set('newKeyName', 'my-app')
            ->call('create');

        $plain = $component->get('plainTextKey');
        $this->assertNotNull($plain);

        // The created key actually works against the ingest API.
        \App\Models\Automation::factory()->create(['tag' => 't']);
        $this->postJson('/api/contacts', ['email' => 'a@b.com', 'tags' => ['t']], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertOk();

        $tokenId = auth()->user()->tokens()->first()->id;
        $component->call('revoke', $tokenId);

        $this->postJson('/api/contacts', ['email' => 'a@b.com', 'tags' => ['t']], [
            'Authorization' => 'Bearer '.$plain,
        ])->assertStatus(401);
    }
}
