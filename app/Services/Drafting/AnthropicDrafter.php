<?php

namespace App\Services\Drafting;

use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SequenceStep;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Drafts an outreach email with the Claude API from the sequence step's
 * user-written instructions plus the contact's data. Follow-up steps get the
 * full prior thread so the draft reads naturally in-thread.
 */
class AnthropicDrafter
{
    /**
     * @return array{subject: string, body: string}
     */
    public function draft(Enrollment $enrollment, SequenceStep $step): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('services.anthropic.timeout', 120))
            ->post(config('services.anthropic.api_url'), [
                'model' => config('services.anthropic.model'),
                'max_tokens' => (int) config('services.anthropic.max_output_tokens', 1500),
                'messages' => [
                    ['role' => 'user', 'content' => $this->buildPrompt($enrollment, $step)],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic API error ('.$response->status().'): '.mb_substr($response->body(), 0, 500)
            );
        }

        $text = $response->json('content.0.text', '');

        $parsed = $this->parseJson($text);

        if (! isset($parsed['subject'], $parsed['body']) || ! is_string($parsed['subject']) || ! is_string($parsed['body'])) {
            throw new RuntimeException('Anthropic draft missing subject/body: '.mb_substr($text, 0, 300));
        }

        return ['subject' => trim($parsed['subject']), 'body' => trim($parsed['body'])];
    }

    protected function buildPrompt(Enrollment $enrollment, SequenceStep $step): string
    {
        $contact = $enrollment->contact;
        $mailbox = $enrollment->mailbox;

        $lines = [
            'You are drafting a one-to-one business outreach email. Today is '.now()->toFormattedDateString().'.',
            '',
            '## Sender',
            'Name: '.($mailbox?->display_name ?: 'the sender'),
            'Email: '.($mailbox?->email ?: '(not yet assigned)'),
            '',
            '## Recipient',
            'Email: '.$contact->email,
        ];

        foreach (['name' => 'Name', 'company' => 'Company', 'website' => 'Website'] as $field => $label) {
            if ($contact->{$field}) {
                $lines[] = $label.': '.$contact->{$field};
            }
        }

        if (! empty($contact->custom)) {
            $lines[] = 'Additional data: '.json_encode($contact->custom, JSON_UNESCAPED_SLASHES);
        }

        $thread = $this->priorThread($enrollment, $step);

        if ($thread !== '') {
            $lines[] = '';
            $lines[] = '## Prior emails in this thread (already sent, no reply yet)';
            $lines[] = $thread;
            $lines[] = '';
            $lines[] = 'This is a follow-up in the same thread: do not re-introduce yourself or re-greet from scratch, reference the earlier note naturally, and keep it shorter than the first email. The subject you return will be ignored in favour of the thread subject, but return one anyway.';
        }

        $lines[] = '';
        $lines[] = '## Drafting instructions for this email (step '.$step->position.')';
        $lines[] = $step->drafting_instructions;
        $lines[] = '';
        $lines[] = 'Write a plain-text email (no HTML, no markdown). Keep it genuinely personal to the recipient data given — never use placeholder brackets. Sign off as the sender.';
        $lines[] = 'Respond with ONLY a JSON object, no other text: {"subject": "...", "body": "..."}';

        return implode("\n", $lines);
    }

    protected function priorThread(Enrollment $enrollment, SequenceStep $step): string
    {
        if ($step->position <= 1) {
            return '';
        }

        return $enrollment->messages()
            ->where('status', Message::STATUS_SENT)
            ->orderBy('sent_at')
            ->get()
            ->map(fn (Message $m) => "Subject: {$m->subject}\nSent: {$m->sent_at?->toDayDateTimeString()}\n\n{$m->body_text}")
            ->implode("\n\n---\n\n");
    }

    /**
     * Parse the model's JSON reply, tolerating markdown code fences.
     */
    protected function parseJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            // Fall back to the outermost {...} block if the model wrapped it in prose.
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Could not parse JSON from Anthropic response: '.mb_substr($text, 0, 300));
        }

        return $decoded;
    }
}
