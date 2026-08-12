<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\Reply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reply>
 */
class ReplyFactory extends Factory
{
    protected $model = Reply::class;

    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'gmail_message_id' => fake()->unique()->uuid(),
            'gmail_thread_id' => fake()->uuid(),
            'from_email' => fake()->safeEmail(),
            'subject' => 'Re: '.fake()->sentence(4),
            'snippet' => fake()->sentence(10),
            'body_text' => fake()->paragraph(),
            'classification' => Reply::CLASS_REPLY,
            'received_at' => now(),
        ];
    }
}
