<?php

namespace Tests\Feature;

use App\Jobs\DraftEmailJob;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\Suppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DraftEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_step_and_moves_to_pending_approval(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"subject": "Hi there", "body": "Hello."}']],
            ]),
        ]);

        $enrollment = Enrollment::factory()->create();
        SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        (new DraftEmailJob($enrollment->id, 1))->handle(app(\App\Services\Drafting\AnthropicDrafter::class));

        $message = Message::query()->where('enrollment_id', $enrollment->id)->firstOrFail();
        $this->assertSame(Message::STATUS_PENDING_APPROVAL, $message->status);
        $this->assertSame('Hi there', $message->subject);
        $this->assertSame('Hi there', $message->ai_subject);
    }

    public function test_noops_for_inactive_enrollment(): void
    {
        Http::fake();
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_STOPPED_REPLY]);
        SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        (new DraftEmailJob($enrollment->id, 1))->handle(app(\App\Services\Drafting\AnthropicDrafter::class));

        $this->assertSame(0, Message::query()->count());
        Http::assertNothingSent();
    }

    public function test_noops_for_suppressed_contact(): void
    {
        Http::fake();
        $enrollment = Enrollment::factory()->create();
        Suppression::suppress($enrollment->contact->email, Suppression::REASON_UNSUBSCRIBED);
        SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        (new DraftEmailJob($enrollment->id, 1))->handle(app(\App\Services\Drafting\AnthropicDrafter::class));

        $this->assertSame(0, Message::query()->count());
        Http::assertNothingSent();
    }

    public function test_does_not_redraft_message_already_pending(): void
    {
        Http::fake();
        $enrollment = Enrollment::factory()->create();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'subject' => 'Keep me',
        ]);

        (new DraftEmailJob($enrollment->id, 1))->handle(app(\App\Services\Drafting\AnthropicDrafter::class));

        $this->assertSame('Keep me', Message::query()->firstOrFail()->subject);
        Http::assertNothingSent();
    }
}
