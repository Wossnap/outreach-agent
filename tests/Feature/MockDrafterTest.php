<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Services\Drafting\AnthropicDrafter;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\MockDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MockDrafterTest extends TestCase
{
    use RefreshDatabase;

    protected function makeEnrollment(): Enrollment
    {
        $contact = Contact::factory()->create([
            'name' => 'Jane',
            'company' => 'Acme',
            'website' => 'https://acme.com',
            'custom' => ['niche' => 'gardening'],
        ]);
        $mailbox = Mailbox::factory()->connected()->create(['display_name' => 'Sean']);

        return Enrollment::factory()->create(['contact_id' => $contact->id, 'mailbox_id' => $mailbox->id]);
    }

    public function test_drafts_offline_without_calling_the_api(): void
    {
        Http::preventStrayRequests();

        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create([
            'automation_id' => $enrollment->automation_id,
            'position' => 1,
            'drafting_instructions' => 'Ask about their backlink strategy.',
        ]);

        $draft = (new MockDrafter)->draft($enrollment, $step);

        $this->assertStringContainsString('[MOCK DRAFT]', $draft['subject']);
        $this->assertStringContainsString('Acme', $draft['subject']);
        $this->assertStringContainsString('Jane', $draft['body']);
        $this->assertStringContainsString('https://acme.com', $draft['body']);
        $this->assertStringContainsString('gardening', $draft['body']);
        $this->assertStringContainsString('Ask about their backlink strategy.', $draft['body']);
        $this->assertStringContainsString('Sean', $draft['body']);
    }

    public function test_is_deterministic(): void
    {
        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 1]);

        $this->assertSame(
            (new MockDrafter)->draft($enrollment, $step),
            (new MockDrafter)->draft($enrollment, $step),
        );
    }

    public function test_follow_up_steps_read_as_follow_ups(): void
    {
        $enrollment = $this->makeEnrollment();
        $step = SequenceStep::factory()->create(['automation_id' => $enrollment->automation_id, 'position' => 2]);

        $draft = (new MockDrafter)->draft($enrollment, $step);

        $this->assertStringContainsString('Following up', $draft['subject']);
        $this->assertStringContainsString('Circling back', $draft['body']);
    }

    public function test_container_resolves_drafter_from_config(): void
    {
        config(['services.anthropic.drafter' => 'mock']);
        $this->assertInstanceOf(MockDrafter::class, app(Drafter::class));

        config(['services.anthropic.drafter' => 'api']);
        $this->assertInstanceOf(AnthropicDrafter::class, app(Drafter::class));
    }
}
