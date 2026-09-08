<?php

namespace App\Services\Drafting;

use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SequenceStep;

/**
 * Offline stand-in for AnthropicDrafter: returns a deterministic draft built
 * from the same inputs the real drafter uses, without calling the Claude API.
 *
 * Enabled with ANTHROPIC_DRAFTER=mock so local testing costs nothing and
 * produces the same output every run. Every draft is prefixed [MOCK DRAFT] so
 * a mock can never be mistaken for a real one in the approval queue.
 */
class MockDrafter implements Drafter
{
    /**
     * @return array{subject: string, body: string}
     */
    public function draft(Enrollment $enrollment, SequenceStep $step): array
    {
        $contact = $enrollment->contact;
        $mailbox = $enrollment->mailbox;
        $isFollowUp = $step->position > 1;

        $who = $contact->name ?: $contact->email;
        $company = $contact->company ?: 'your company';

        $subject = $isFollowUp
            ? '[MOCK DRAFT] Following up — '.$company
            : '[MOCK DRAFT] '.$company.' — quick question';

        $lines = $isFollowUp
            ? ['Hi '.$who.',', '', 'Circling back on my last note about '.$company.'.']
            : ['Hi '.$who.',', '', 'I came across '.$company.' and wanted to reach out.'];

        if ($contact->domain) {
            $lines[] = 'Company domain on file: '.$contact->domain;
        }

        if ([] !== $extra = $contact->extraForDrafting()) {
            $lines[] = 'Custom data on file: '.json_encode($extra, JSON_UNESCAPED_SLASHES);
        }

        // Echo the inputs back so a tester can confirm the step's instructions
        // and the prior thread actually reached the drafter.
        $lines[] = '';
        $lines[] = 'Step '.$step->position.' instructions used: '.$this->summarise($step->drafting_instructions);
        $lines[] = 'Prior emails in thread: '.$this->priorThreadCount($enrollment, $step);
        $lines[] = '';
        $lines[] = 'Best,';
        $lines[] = $mailbox?->display_name ?: 'the sender';

        return ['subject' => $subject, 'body' => implode("\n", $lines)];
    }

    protected function summarise(?string $instructions): string
    {
        $instructions = trim((string) $instructions);

        if ($instructions === '') {
            return '(none set)';
        }

        return mb_strimwidth(preg_replace('/\s+/', ' ', $instructions), 0, 120, '…');
    }

    protected function priorThreadCount(Enrollment $enrollment, SequenceStep $step): int
    {
        if ($step->position <= 1) {
            return 0;
        }

        return $enrollment->messages()->where('status', Message::STATUS_SENT)->count();
    }
}
