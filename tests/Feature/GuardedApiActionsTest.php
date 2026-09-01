<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two API actions remove a safeguard rather than just moving data, so each
 * needs a config switch on top of a key with the right ability. Both are off
 * unless someone deliberately turns them on.
 */
class GuardedApiActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function headers(array $abilities): array
    {
        $token = User::factory()->create()->createToken('t', $abilities)->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    protected function pendingDraft(): Message
    {
        Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['status' => Enrollment::STATUS_ACTIVE]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        return Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
        ]);
    }

    public function test_a_fresh_install_ships_with_both_actions_off(): void
    {
        // Asserted against .env.example rather than config(), which would only
        // report whatever this machine happens to have set. .env.example is
        // what a new install copies, so it is the actual shipped default.
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('OUTREACH_API_ALLOW_APPROVAL=false', $example);
        $this->assertStringContainsString('OUTREACH_API_ALLOW_SUPPRESSION_REMOVAL=false', $example);
    }

    public function test_approval_is_refused_while_the_switch_is_off(): void
    {
        config(['outreach.api.allow_approval' => false]);

        $message = $this->pendingDraft();

        $this->postJson("/api/messages/{$message->id}/approve", [], $this->headers(['approve']))
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        // Still waiting on a human, which is the whole point of the switch.
        $this->assertSame(Message::STATUS_PENDING_APPROVAL, $message->fresh()->status);
    }

    public function test_approval_works_once_the_switch_is_on(): void
    {
        config(['outreach.api.allow_approval' => true]);

        $message = $this->pendingDraft();

        $this->postJson("/api/messages/{$message->id}/approve", [], $this->headers(['approve']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertContains(
            $message->fresh()->status,
            [Message::STATUS_APPROVED, Message::STATUS_SCHEDULED],
        );
    }

    public function test_the_switch_alone_is_not_enough_without_the_ability(): void
    {
        config(['outreach.api.allow_approval' => true]);

        $message = $this->pendingDraft();

        $this->postJson("/api/messages/{$message->id}/approve", [], $this->headers(['read', 'write']))
            ->assertStatus(403);

        $this->assertSame(Message::STATUS_PENDING_APPROVAL, $message->fresh()->status);
    }

    public function test_un_suppressing_is_refused_while_the_switch_is_off(): void
    {
        config(['outreach.api.allow_suppression_removal' => false]);

        Suppression::suppress('gone@example.com', Suppression::REASON_UNSUBSCRIBED);

        $this->deleteJson('/api/suppressions/gone@example.com', [], $this->headers(['write']))
            ->assertStatus(403);

        $this->assertTrue(Suppression::isSuppressed('gone@example.com'));
    }

    public function test_un_suppressing_works_once_the_switch_is_on(): void
    {
        config(['outreach.api.allow_suppression_removal' => true]);

        Suppression::suppress('gone@example.com', Suppression::REASON_UNSUBSCRIBED);

        $this->deleteJson('/api/suppressions/gone@example.com', [], $this->headers(['write']))
            ->assertOk();

        $this->assertFalse(Suppression::isSuppressed('gone@example.com'));
    }

    public function test_the_refusal_says_which_setting_turns_it_on(): void
    {
        config(['outreach.api.allow_approval' => false]);

        $message = $this->pendingDraft();

        $this->postJson("/api/messages/{$message->id}/approve", [], $this->headers(['approve']))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'This action is disabled. An administrator can enable it with OUTREACH_API_ALLOW_APPROVAL=true.']);
    }
}
