<?php

namespace Database\Factories;

use App\Models\Automation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    public function definition(): array
    {
        return [
            'tag' => fake()->unique()->slug(2),
            'name' => fake()->sentence(3),
            'active' => true,
        ];
    }
}
