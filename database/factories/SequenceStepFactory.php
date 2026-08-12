<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\SequenceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceStep>
 */
class SequenceStepFactory extends Factory
{
    protected $model = SequenceStep::class;

    public function definition(): array
    {
        return [
            'automation_id' => Automation::factory(),
            'position' => 1,
            'delay_days' => 0,
            'delay_hours' => 0,
            'drafting_instructions' => 'Write a short, friendly outreach email introducing our product.',
            'active' => true,
        ];
    }
}
