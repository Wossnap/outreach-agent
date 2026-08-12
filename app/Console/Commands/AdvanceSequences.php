<?php

namespace App\Console\Commands;

use App\Jobs\DraftEmailJob;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SequenceStep;
use Illuminate\Console\Command;

class AdvanceSequences extends Command
{
    protected $signature = 'outreach:advance-sequences';

    protected $description = 'Draft the next step for enrollments whose delay has elapsed with no reply';

    public function handle(): int
    {
        $advanced = 0;

        Enrollment::query()
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->where('current_step', '>', 0)
            ->with('automation')
            ->chunkById(200, function ($enrollments) use (&$advanced) {
                foreach ($enrollments as $enrollment) {
                    if ($this->advance($enrollment)) {
                        $advanced++;
                    }
                }
            });

        $this->info("Queued drafting for {$advanced} enrollments.");

        return self::SUCCESS;
    }

    protected function advance(Enrollment $enrollment): bool
    {
        $nextStep = $enrollment->automation
            ->activeSteps()
            ->where('position', '>', $enrollment->current_step)
            ->orderBy('position')
            ->first();

        if (! $nextStep) {
            return false;
        }

        // A message already exists for the next step (drafting, pending,
        // scheduled, or terminal) — nothing to do.
        $alreadyStarted = Message::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $nextStep->id)
            ->exists();

        if ($alreadyStarted) {
            return false;
        }

        $lastSentAt = Message::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', Message::STATUS_SENT)
            ->max('sent_at');

        if (! $lastSentAt) {
            return false;
        }

        $dueAt = \Carbon\Carbon::parse($lastSentAt)->addHours($this->delayInHours($nextStep));

        if (now()->lessThan($dueAt)) {
            return false;
        }

        DraftEmailJob::dispatch($enrollment->id, $nextStep->position);

        return true;
    }

    protected function delayInHours(SequenceStep $step): int
    {
        return $step->delayInHours();
    }
}
