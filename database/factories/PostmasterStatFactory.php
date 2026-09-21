<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\PostmasterStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostmasterStat>
 */
class PostmasterStatFactory extends Factory
{
    protected $model = PostmasterStat::class;

    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'date' => now()->subDay()->toDateString(),
            'spam_rate' => 0.001,
            'auth_success_rate' => 1.0,
            'delivery_error_rate' => 0.0,
            'raw' => ['SPAM_RATE' => 0.001, 'AUTH_SUCCESS_RATE' => 1.0, 'DELIVERY_ERROR_RATE' => 0.0],
        ];
    }
}
