<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Services\Drafting\AnthropicDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicDrafterTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeAnthropic(string $text, int $status = 200): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
            ], $status),
        ]);
    }

    protected function makeEnrollment(): Enrollment
    {
        $contact = Contact::factory()->create(['name' => 'Jane', 'company' => 'Acme']);
        $mailbox = Mailbox::factory()->connected()->create();

        return Enrollment::factory()->create(['contact_id' => $contact->id, 'mailbox_id' => $mailbox->id]);
    }

    public function test_parses_clean_json(): void
    {
        $this->fakeAnthropic('{"subject": "Hi Jane", "body": "Hello there."}');
        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        $draft = app(AnthropicDrafter::class)->draft($enrollment, $step);

        $this->assertSame('Hi Jane', $draft['subject']);
        $this->assertSame('Hello there.', $draft['body']);
    }

    public function test_tolerates_markdown_fences(): void
    {
        $this->fakeAnthropic("```json\n{\"subject\": \"Hi\", \"body\": \"Yo\"}\n```");
        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        $draft = app(AnthropicDrafter::class)->draft($enrollment, $step);

        $this->assertSame('Hi', $draft['subject']);
    }

    public function test_throws_on_api_error(): void
    {
        $this->fakeAnthropic('overloaded', 529);
        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        $this->expectException(RuntimeException::class);

        app(AnthropicDrafter::class)->draft($enrollment, $step);
    }

    public function test_throws_on_unparseable_response(): void
    {
        $this->fakeAnthropic('Sure! Here is a lovely email for you.');
        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id]);

        $this->expectException(RuntimeException::class);

        app(AnthropicDrafter::class)->draft($enrollment, $step);
    }

    public function test_follow_up_prompt_includes_prior_thread(): void
    {
        $this->fakeAnthropic('{"subject": "Re: Hi", "body": "Following up."}');
        $enrollment = $this->makeEnrollment();
        $step1 = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);
        $step2 = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 2]);

        Message::factory()->sent()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step1->id,
            'contact_id' => $enrollment->contact_id,
            'subject' => 'Original subject line',
            'body_text' => 'Original body text here.',
        ]);

        app(AnthropicDrafter::class)->draft($enrollment, $step2);

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][0]['content'];

            return str_contains($prompt, 'Original subject line')
                && str_contains($prompt, 'Original body text here.')
                && str_contains($prompt, 'follow-up');
        });
    }
}
