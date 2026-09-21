<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $token = User::factory()->create()->createToken('reader', ['read'])->plainTextToken;
        $this->headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_every_read_route_refuses_a_missing_key(): void
    {
        foreach (['/api/contacts', '/api/replies', '/api/suppressions', '/api/messages',
            '/api/enrollments', '/api/automations', '/api/mailboxes', '/api/activity', '/api/stats'] as $route) {
            $this->getJson($route)->assertStatus(401);
        }
    }

    public function test_a_malformed_key_is_refused_without_reaching_the_database(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql.' '.json_encode($q->bindings);
        });

        // Braces left behind after pasting over the documentation page's
        // placeholder. The id part is then "{2" rather than a number.
        $this->getJson('/api/contacts', ['Authorization' => 'Bearer {2|lLv6oWTLCPjry5fsqC1rN4cm}'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid API key.');

        // Asserting the 401 alone is not enough: SQLite is loosely typed and
        // would return no rows either way. Postgres rejects a non-numeric id
        // outright, so reaching the database at all is what turned a mistyped
        // key into a 500 with the database host in the log.
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('{2', $query);
        }
    }

    public function test_a_key_that_is_merely_wrong_is_still_refused(): void
    {
        $this->getJson('/api/contacts', ['Authorization' => 'Bearer 999|nosuchsecret'])
            ->assertStatus(401);

        $this->getJson('/api/contacts', ['Authorization' => 'Bearer nonsense-with-no-pipe'])
            ->assertStatus(401);
    }

    public function test_contacts_are_listed_and_filterable(): void
    {
        Contact::factory()->create(['email' => 'a@one.com', 'company' => 'Acme']);
        Contact::factory()->create(['email' => 'b@two.com', 'company' => 'Globex']);

        $this->getJson('/api/contacts', $this->headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/contacts?company=Acme', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'a@one.com');

        $this->getJson('/api/contacts?q=globex', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_contacts_can_be_filtered_by_suppression(): void
    {
        Contact::factory()->create(['email' => 'clean@one.com']);
        Contact::factory()->create(['email' => 'gone@two.com']);
        Suppression::suppress('gone@two.com', Suppression::REASON_UNSUBSCRIBED);

        $this->getJson('/api/contacts?suppressed=true', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'gone@two.com');

        $this->getJson('/api/contacts?suppressed=false', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'clean@one.com');
    }

    public function test_a_contact_can_be_fetched_by_email_as_well_as_id(): void
    {
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);

        $this->getJson('/api/contacts/'.$contact->id, $this->headers)
            ->assertOk()
            ->assertJsonPath('data.email', 'jane@example.com');

        $this->getJson('/api/contacts/jane@example.com', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.id', $contact->id);
    }

    public function test_a_contact_carries_its_history(): void
    {
        $enrollment = Enrollment::factory()->create();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);
        Message::factory()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
        ]);
        Reply::factory()->create([
            'contact_id' => $enrollment->contact_id,
            'enrollment_id' => $enrollment->id,
        ]);

        $this->getJson('/api/contacts/'.$enrollment->contact_id, $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data.enrollments')
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.suppressed', false);
    }

    public function test_an_unknown_contact_is_a_json_404(): void
    {
        $this->getJson('/api/contacts/nobody@example.com', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_replies_are_filterable_by_classification(): void
    {
        Reply::factory()->create(['classification' => Reply::CLASS_REPLY]);
        Reply::factory()->create(['classification' => Reply::CLASS_BOUNCE]);

        $this->getJson('/api/replies?classification=bounce', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.classification', 'bounce');
    }

    public function test_the_suppression_list_is_readable(): void
    {
        Suppression::suppress('gone@example.com', Suppression::REASON_UNSUBSCRIBED);

        $this->getJson('/api/suppressions', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.email', 'gone@example.com')
            ->assertJsonPath('data.0.reason', 'unsubscribed');
    }

    public function test_drafts_awaiting_approval_are_readable(): void
    {
        $enrollment = Enrollment::factory()->create();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'subject' => 'Waiting on you',
        ]);

        $this->getJson('/api/messages?status=pending_approval', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Waiting on you');
    }

    public function test_automations_come_back_with_their_steps(): void
    {
        $automation = Automation::factory()->create(['tag' => 'seo']);
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 1]);
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 2]);

        $this->getJson('/api/automations/seo', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.tag', 'seo')
            ->assertJsonCount(2, 'data.steps');
    }

    public function test_mailboxes_never_expose_google_tokens(): void
    {
        Mailbox::factory()->connected()->create(['email' => 'sender@outreach.test']);

        $response = $this->getJson('/api/mailboxes', $this->headers)->assertOk();

        $this->assertStringNotContainsString('google_access_token', $response->getContent());
        $this->assertStringNotContainsString('google_refresh_token', $response->getContent());
        $response->assertJsonPath('data.0.email', 'sender@outreach.test');
    }

    public function test_the_activity_log_is_filterable_by_level(): void
    {
        ActivityLog::record('sent', 'fine');
        ActivityLog::record('send_failed', 'bad', ActivityLog::LEVEL_ERROR);

        $this->getJson('/api/activity?level=error', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'send_failed');
    }

    public function test_stats_report_null_rates_when_nothing_has_been_sent(): void
    {
        $this->getJson('/api/stats', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.sending.sent', 0)
            // Null, not zero: zero would read as "nobody replied" rather than
            // "there is no data yet".
            ->assertJsonPath('data.sending.reply_rate', null)
            ->assertJsonPath('data.sending.bounce_rate', null)
            ->assertJsonPath('data.sending.open_rate', null)
            ->assertJsonPath('data.postmaster.worst', null);
    }

    public function test_stats_report_opens_against_tracked_messages_and_the_worst_spam_domain(): void
    {
        $mailbox = Mailbox::factory()->connected()->create();
        Message::factory()->count(2)->sent()->create(['mailbox_id' => $mailbox->id, 'open_token' => fn () => Message::generateOpenToken(), 'first_opened_at' => now()]);
        Message::factory()->count(2)->sent()->create(['mailbox_id' => $mailbox->id, 'open_token' => fn () => Message::generateOpenToken()]);
        Message::factory()->sent()->create(['mailbox_id' => $mailbox->id]);

        $calm = \App\Models\Domain::factory()->create(['name' => 'calm.test']);
        $noisy = \App\Models\Domain::factory()->create(['name' => 'noisy.test']);
        \App\Models\PostmasterStat::factory()->create(['domain_id' => $calm->id, 'spam_rate' => 0.0005]);
        \App\Models\PostmasterStat::factory()->create(['domain_id' => $noisy->id, 'spam_rate' => 0.004]);

        $this->getJson('/api/stats', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.sending.sent', 5)
            ->assertJsonPath('data.sending.tracked', 4)
            ->assertJsonPath('data.sending.opened', 2)
            ->assertJsonPath('data.sending.open_rate', 0.5)
            ->assertJsonPath('data.postmaster.worst.domain', 'noisy.test')
            ->assertJsonPath('data.postmaster.worst.spam_rate', 0.004);
    }

    public function test_page_size_is_clamped(): void
    {
        Contact::factory()->count(3)->create();

        $this->getJson('/api/contacts?per_page=99999', $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', (int) config('outreach.api.max_page_size'));
    }
}
