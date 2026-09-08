<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Reply;
use App\Models\SequenceStep;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Realistic sample data for generating the API documentation.
 *
 * Scribe calls the real GET endpoints to capture example responses, so the
 * quality of the docs is the quality of this data. Empty tables produce docs
 * full of empty arrays, which tell a reader nothing about the shape.
 *
 * Only ever run against a throwaway database. See "Regenerating the API docs"
 * in the README.
 */
class DocsExampleSeeder extends Seeder
{
    public function run(): void
    {

        $user = User::create(['name' => 'Docs', 'email' => 'docs@example.com', 'password' => bcrypt(Str::random(32))]);
        $token = $user->createToken('docs', ['read', 'write', 'approve'])->plainTextToken;

        $domain = Domain::create(['name' => 'outreach.example', 'dkim_selector' => 'google', 'health_status' => 'healthy']);

        $mailbox = Mailbox::create([
            'domain_id' => $domain->id, 'email' => 'hello@outreach.example', 'display_name' => 'Alex Rivera',
            'status' => 'active', 'health_status' => 'healthy', 'daily_cap' => 40,
            'send_window_start' => '09:00', 'send_window_end' => '17:00', 'send_timezone' => 'UTC',
            'sent_7d' => 84, 'bounce_rate_7d' => 0.0119, 'reply_rate_7d' => 0.0714,
            'last_polled_at' => now()->subMinutes(2),
        ]);

        $automation = Automation::create([
            'tag' => 'seo-backlinks', 'name' => 'SEO backlinks outreach',
            'description' => 'Three touches for sites that could carry a link back to us.', 'active' => true,
        ]);

        $step1 = SequenceStep::create([
            'automation_id' => $automation->id, 'position' => 1, 'delay_days' => 0, 'delay_hours' => 0,
            'drafting_instructions' => 'Introduce yourself as the founder. Name one specific page on their site and why a link swap makes sense. Under 120 words, one clear ask.',
            'active' => true,
        ]);
        SequenceStep::create([
            'automation_id' => $automation->id, 'position' => 2, 'delay_days' => 3, 'delay_hours' => 0,
            'drafting_instructions' => 'Short nudge referencing the first note. Do not re-introduce yourself.',
            'active' => true,
        ]);

        $contact = Contact::create([
            'email' => 'jane.doe@acme.example', 'name' => 'Jane Doe', 'first_name' => 'Jane', 'last_name' => 'Doe',
            'company' => 'Acme Ltd', 'domain' => 'acme.example',
            'extra' => ['tube-trend-tool' => ['industry' => 'SaaS', 'employees' => 40]], 'source' => 'tube-trend-tool',
        ]);
        $sam = Contact::create([
            'email' => 'sam.patel@globex.example', 'name' => 'Sam Patel', 'first_name' => 'Sam', 'last_name' => 'Patel',
            'company' => 'Globex', 'domain' => 'globex.example', 'source' => 'tube-trend-tool',
        ]);

        $enrollment = Enrollment::create([
            'contact_id' => $contact->id, 'automation_id' => $automation->id, 'mailbox_id' => $mailbox->id,
            'status' => 'active', 'current_step' => 1, 'gmail_thread_id' => '18f2a9c4d1b2e3f4',
        ]);

        $sent = Message::create([
            'enrollment_id' => $enrollment->id, 'sequence_step_id' => $step1->id, 'mailbox_id' => $mailbox->id,
            'contact_id' => $contact->id, 'status' => 'sent',
            'subject' => 'Your guide on nursery irrigation',
            'body_text' => "Hi Jane,\n\nI read your piece on drip irrigation and it lines up closely with what we publish.\n\nWould a link swap make sense?\n\nAlex",
            'sent_at' => now()->subDay(), 'gmail_message_id' => '18f2a9c4d1b2e3f4',
            'rfc_message_id' => '<CAO123@mail.gmail.com>', 'gmail_thread_id' => '18f2a9c4d1b2e3f4',
        ]);

        // Sam gets his own enrollment: messages are unique per
        // (enrollment, step), so a second draft cannot hang off Jane's.
        $samEnrollment = Enrollment::create([
            'contact_id' => $sam->id, 'automation_id' => $automation->id, 'mailbox_id' => $mailbox->id,
            'status' => 'active', 'current_step' => 0,
        ]);

        Message::create([
            'enrollment_id' => $samEnrollment->id, 'sequence_step_id' => $step1->id, 'mailbox_id' => $mailbox->id,
            'contact_id' => $sam->id,
            'status' => 'pending_approval',
            'subject' => 'Quick thought on your resources page',
            'body_text' => "Hi Sam,\n\nYour resources page is missing a section we could help fill.\n\nWorth a quick chat?\n\nAlex",
            'ai_subject' => 'Quick thought on your resources page',
        ]);

        Reply::create([
            'mailbox_id' => $mailbox->id, 'enrollment_id' => $enrollment->id, 'contact_id' => $contact->id,
            'message_id' => $sent->id, 'gmail_message_id' => '18f2b0e5c2d3', 'gmail_thread_id' => '18f2a9c4d1b2e3f4',
            'from_email' => 'jane.doe@acme.example', 'subject' => 'Re: Your guide on nursery irrigation',
            'snippet' => 'Happy to take a look, send it over.',
            'body_text' => "Happy to take a look, send it over.\n\nJane",
            'classification' => 'reply', 'received_at' => now()->subHours(6),
        ]);

        Suppression::create(['email' => 'no.thanks@globex.example', 'reason' => 'unsubscribed']);

        ActivityLog::create(['event' => 'email_sent', 'level' => 'info', 'message' => 'Sent to jane.doe@acme.example', 'subject_type' => Message::class, 'subject_id' => $sent->id]);
        ActivityLog::create(['event' => 'send_failed', 'level' => 'error', 'message' => 'Gmail returned 429 for hello@outreach.example', 'retryable' => true]);

        file_put_contents(getenv('TOKEN_OUT') ?: storage_path('docs-token.txt'), $token);
    }
}
