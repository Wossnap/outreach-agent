<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenTrackingController;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The open pixel: always a GIF, never a session, and an open recorded only
 * for a sent message that has been out long enough to have reached a person.
 */
class OpenTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function tracked(array $attributes = []): Message
    {
        return Message::factory()->sent()->create(array_merge([
            'open_token' => Message::generateOpenToken(),
            'sent_at' => now()->subMinutes(30),
        ], $attributes));
    }

    public function test_an_unknown_token_still_gets_the_gif_and_no_cookie(): void
    {
        $response = $this->get('/t/o/'.str_repeat('z', 40).'.gif');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/gif')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');

        $this->assertSame(base64_decode(OpenTrackingController::GIF), $response->getContent());
        // Fetched by a mail client, not a person on the site: no session.
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_a_fetch_records_the_open_and_a_second_one_only_counts(): void
    {
        $message = $this->tracked();

        $this->get('/t/o/'.$message->open_token.'.gif')->assertOk();

        $fresh = $message->fresh();
        $this->assertNotNull($fresh->first_opened_at);
        $this->assertNotNull($fresh->last_opened_at);
        $this->assertSame(1, $fresh->open_count);
        $first = $fresh->first_opened_at;

        $this->travel(5)->minutes();
        $this->get('/t/o/'.$message->open_token.'.gif')->assertOk();

        $again = $message->fresh();
        $this->assertSame(2, $again->open_count);
        $this->assertTrue($again->first_opened_at->equalTo($first));
        $this->assertTrue($again->last_opened_at->greaterThan($first));
    }

    public function test_a_fetch_straight_after_sending_is_a_scanner_not_a_person(): void
    {
        $message = $this->tracked(['sent_at' => now()->subSeconds(2)]);

        $this->get('/t/o/'.$message->open_token.'.gif')->assertOk();

        $this->assertNull($message->fresh()->first_opened_at);
        $this->assertSame(0, $message->fresh()->open_count);
    }

    public function test_only_a_sent_message_can_be_opened(): void
    {
        $message = Message::factory()->pendingApproval()->create(['open_token' => Message::generateOpenToken()]);

        $this->get('/t/o/'.$message->open_token.'.gif')->assertOk();

        $this->assertSame(0, $message->fresh()->open_count);
    }
}
