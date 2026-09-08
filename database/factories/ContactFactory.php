<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'company' => fake()->company(),
            'domain' => fake()->domainName(),
            // Confirmed, because that is what a contact on file means: the
            // address has been checked and may be sent to. A contact whose
            // address is unknown or unchecked is the exception, so it is the
            // one that has to be asked for.
            'email_status' => Contact::EMAIL_VALID,
        ];
    }

    /** Nobody has looked at this address yet. */
    public function pending(): static
    {
        return $this->state(['email_status' => Contact::EMAIL_PENDING]);
    }

    /** Somebody we can name but have no address for. */
    public function withoutAnEmail(): static
    {
        return $this->state([
            'email' => null,
            'email_status' => Contact::EMAIL_PENDING,
            'profile_url' => 'https://www.linkedin.com/in/'.fake()->unique()->userName(),
        ]);
    }
}
