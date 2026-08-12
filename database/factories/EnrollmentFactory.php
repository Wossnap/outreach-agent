<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'automation_id' => Automation::factory(),
            'status' => Enrollment::STATUS_ACTIVE,
            'current_step' => 0,
        ];
    }
}
