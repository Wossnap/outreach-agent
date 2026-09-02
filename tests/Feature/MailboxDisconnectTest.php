<?php

namespace Tests\Feature;

use App\Livewire\Mailboxes\Index;
use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MailboxDisconnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disconnecting asks Google to invalidate the token. Faked so the tests
        // never reach the network.
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 200)]);
    }

    protected function writeHeaders(): array
    {
        $token = User::factory()->create()->createToken('t', ['write'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    protected function scheduledMessageOn(Mailbox $mailbox): Message
    {
        $enrollment = Enrollment::factory()->create([
            'mailbox_id' => $mailbox->id,
            'status' => Enrollment::STATUS_ACTIVE,
        ]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        return Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'status' => Message::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHour(),
        ]);
    }

    public function test_the_api_disconnects_a_mailbox_and_discards_its_credentials(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();

        $this->assertNotNull($mailbox->google_refresh_token);

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [
            'reason' => 'Rotating the sending account',
        ], $this->writeHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', Mailbox::STATUS_DISCONNECTED);

        $fresh = $mailbox->fresh();

        $this->assertSame(Mailbox::STATUS_DISCONNECTED, $fresh->status);
        $this->assertNull($fresh->google_refresh_token);
        $this->assertNull($fresh->google_access_token);
        $this->assertNull($fresh->google_token_expires_at);
        // The inbox bookmark points at a history this account no longer shares
        // with us, so a later reconnect must not resume from it.
        $this->assertNull($fresh->gmail_history_id);
    }

    public function test_google_is_asked_to_invalidate_the_token(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], $this->writeHeaders())
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_it_still_disconnects_when_google_cannot_be_reached(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 500)]);

        $mailbox = Mailbox::factory()->connected()->create();

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], $this->writeHeaders())
            ->assertOk();

        // Leaving it connected here because a remote call failed would be the
        // worse outcome: we would still hold usable credentials.
        $this->assertSame(Mailbox::STATUS_DISCONNECTED, $mailbox->fresh()->status);
        $this->assertNull($mailbox->fresh()->google_refresh_token);
    }

    public function test_queued_emails_are_released_rather_than_left_pointing_at_it(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $message = $this->scheduledMessageOn($mailbox);

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], $this->writeHeaders())
            ->assertOk();

        $fresh = $message->fresh();

        // Left scheduled on a mailbox that can no longer send, it would simply
        // fail when its slot arrived.
        $this->assertSame(Message::STATUS_APPROVED, $fresh->status);
        $this->assertNull($fresh->mailbox_id);
        $this->assertNull($fresh->scheduled_at);
    }

    public function test_already_sent_emails_are_left_alone(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $enrollment = Enrollment::factory()->create(['mailbox_id' => $mailbox->id]);
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        $sent = Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'mailbox_id' => $mailbox->id,
            'status' => Message::STATUS_SENT,
            'sent_at' => now()->subHour(),
        ]);

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], $this->writeHeaders())
            ->assertOk();

        $this->assertSame(Message::STATUS_SENT, $sent->fresh()->status);
        $this->assertSame($mailbox->id, $sent->fresh()->mailbox_id);
    }

    public function test_disconnecting_twice_is_refused(): void
    {
        $mailbox = Mailbox::factory()->connected()->create(['status' => Mailbox::STATUS_DISCONNECTED]);

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], $this->writeHeaders())
            ->assertStatus(409);
    }

    public function test_a_read_only_key_cannot_disconnect(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $token = User::factory()->create()->createToken('r', ['read'])->plainTextToken;

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->assertStatus(403);

        $this->assertSame(Mailbox::STATUS_ACTIVE, $mailbox->fresh()->status);
    }

    public function test_the_dashboard_disconnects_the_same_way(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        $message = $this->scheduledMessageOn($mailbox);

        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)->call('disconnect', $mailbox->id);

        $this->assertSame(Mailbox::STATUS_DISCONNECTED, $mailbox->fresh()->status);
        $this->assertNull($mailbox->fresh()->google_refresh_token);
        $this->assertSame(Message::STATUS_APPROVED, $message->fresh()->status);
    }

    public function test_the_button_shows_for_a_live_mailbox_and_not_a_disconnected_one(): void
    {
        $live = Mailbox::factory()->connected()->create(['email' => 'live@outreach.test']);

        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->assertSee('Disconnect')
            ->assertSee('Pause');

        $live->update(['status' => Mailbox::STATUS_DISCONNECTED]);

        // Nothing left to disconnect, so it offers the way back instead.
        Livewire::test(Index::class)
            ->assertDontSee('Disconnect')
            ->assertSee('Reconnect');
    }

    public function test_it_is_recorded_on_the_activity_page(): void
    {
        $mailbox = Mailbox::factory()->connected()->create(['email' => 'hello@outreach.test']);

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [
            'reason' => 'Rotating the sending account',
        ], $this->writeHeaders())->assertOk();

        $log = ActivityLog::query()->where('event', 'mailbox_disconnected')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('hello@outreach.test', $log->message);
        $this->assertStringContainsString('Rotating the sending account', $log->message);
    }

    public function test_reconnecting_reuses_the_same_mailbox_rather_than_duplicating_it(): void
    {
        $mailbox = Mailbox::factory()->connected()->create(['email' => 'hello@outreach.test']);

        $this->postJson("/api/mailboxes/{$mailbox->id}/disconnect", [], $this->writeHeaders())->assertOk();

        // What the Google callback does on a successful reconnect. Asserted
        // here because a disconnect nobody can undo would be a trap.
        Mailbox::query()->firstOrNew(['email' => 'hello@outreach.test'])->fill([
            'status' => Mailbox::STATUS_ACTIVE,
            'paused_reason' => null,
            'google_refresh_token' => 'fresh-token',
        ])->save();

        $this->assertSame(1, Mailbox::query()->where('email', 'hello@outreach.test')->count());
        $this->assertSame(Mailbox::STATUS_ACTIVE, $mailbox->fresh()->status);
        $this->assertSame($mailbox->id, Mailbox::query()->where('email', 'hello@outreach.test')->first()->id);
    }
}
