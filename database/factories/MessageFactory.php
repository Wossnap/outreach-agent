<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SequenceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        $enrollment = Enrollment::factory();

        return [
            'enrollment_id' => $enrollment,
            'sequence_step_id' => SequenceStep::factory(),
            'contact_id' => Contact::factory(),
            'status' => Message::STATUS_DRAFTING,
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn () => [
            'status' => Message::STATUS_PENDING_APPROVAL,
            'subject' => fake()->sentence(6),
            'body_text' => fake()->paragraphs(2, true),
            'ai_subject' => fake()->sentence(6),
            'ai_body' => fake()->paragraphs(2, true),
        ]);
    }

    public function scheduled(): static
    {
        return $this->pendingApproval()->state(fn () => [
            'status' => Message::STATUS_SCHEDULED,
            'approved_at' => now(),
            'scheduled_at' => now()->addMinutes(10),
        ]);
    }

    public function sent(): static
    {
        return $this->pendingApproval()->state(fn () => [
            'status' => Message::STATUS_SENT,
            'approved_at' => now()->subHour(),
            'scheduled_at' => now()->subMinutes(30),
            'sent_at' => now()->subMinutes(30),
            'gmail_message_id' => fake()->uuid(),
            'gmail_thread_id' => fake()->uuid(),
            'rfc_message_id' => '<'.fake()->uuid().'@example.com>',
        ]);
    }
}
