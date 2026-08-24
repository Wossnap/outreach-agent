<?php

namespace App\Services\Drafting;

use App\Models\Enrollment;
use App\Models\SequenceStep;

interface Drafter
{
    /**
     * Draft the email for this enrollment's step.
     *
     * @return array{subject: string, body: string}
     */
    public function draft(Enrollment $enrollment, SequenceStep $step): array;
}
