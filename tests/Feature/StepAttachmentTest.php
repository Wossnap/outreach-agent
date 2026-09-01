<?php

namespace Tests\Feature;

use App\Livewire\Automations\Edit;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\Attachments\StepAttachmentStore;
use App\Services\Gmail\GmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class StepAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function makeMessage(SequenceStep $step): Message
    {
        $mailbox = Mailbox::factory()->connected()->create(['email' => 'sender@outreach.test']);
        $contact = Contact::factory()->create(['email' => 'jane@example.com']);
        $enrollment = Enrollment::factory()->create([
            'contact_id' => $contact->id,
            'mailbox_id' => $mailbox->id,
            'automation_id' => $step->automation_id,
        ]);

        return Message::factory()->pendingApproval()->create([
            'enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'contact_id' => $contact->id,
            'mailbox_id' => $mailbox->id,
            'body_text' => 'Hello there.',
        ]);
    }

    public function test_a_step_without_attachments_still_sends_a_plain_text_email(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);
        $mime = app(GmailSender::class)->buildMime($this->makeMessage($step), '<id@outreach.test>');

        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        $this->assertStringNotContainsString('multipart/mixed', $mime);
    }

    public function test_an_attachment_is_carried_as_a_multipart_part(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);

        app(StepAttachmentStore::class)->add(
            $step,
            UploadedFile::fake()->createWithContent('one-pager.pdf', 'PDF-BYTES'),
        );

        $mime = app(GmailSender::class)->buildMime($this->makeMessage($step->fresh()), '<id@outreach.test>');

        $this->assertStringContainsString('Content-Type: multipart/mixed; boundary="outreach-', $mime);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="one-pager.pdf"', $mime);
        // The file's bytes, base64 encoded, are actually in the message.
        $this->assertStringContainsString(base64_encode('PDF-BYTES'), $mime);
        // And the body survived alongside it.
        $this->assertStringContainsString(base64_encode('Hello there.'), $mime);
    }

    public function test_sending_fails_loudly_when_the_file_is_gone(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);

        $stored = app(StepAttachmentStore::class)->add(
            $step,
            UploadedFile::fake()->createWithContent('deck.pdf', 'BYTES'),
        );

        Storage::disk('local')->delete($stored['path']);

        // Sending anyway would deliver an email whose text refers to a file
        // that is not attached, with nobody the wiser.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Attachment missing from storage/');

        app(GmailSender::class)->buildMime($this->makeMessage($step->fresh()), '<id@outreach.test>');
    }

    public function test_an_attachment_belongs_to_one_step_only(): void
    {
        $first = SequenceStep::factory()->create(['position' => 1]);
        $second = SequenceStep::factory()->create([
            'automation_id' => $first->automation_id,
            'position' => 2,
        ]);

        app(StepAttachmentStore::class)->add(
            $first,
            UploadedFile::fake()->createWithContent('one-pager.pdf', 'PDF-BYTES'),
        );

        $mime = app(GmailSender::class)->buildMime($this->makeMessage($second->fresh()), '<id@outreach.test>');

        $this->assertStringNotContainsString('multipart/mixed', $mime);
    }

    public function test_the_per_step_limit_is_enforced(): void
    {
        config(['outreach.attachments.max_per_step' => 2]);

        $step = SequenceStep::factory()->create(['position' => 1]);
        $store = app(StepAttachmentStore::class);

        $store->add($step, UploadedFile::fake()->createWithContent('a.pdf', 'a'));
        $store->add($step->fresh(), UploadedFile::fake()->createWithContent('b.pdf', 'b'));

        $this->expectException(RuntimeException::class);
        $store->add($step->fresh(), UploadedFile::fake()->createWithContent('c.pdf', 'c'));
    }

    public function test_removing_an_attachment_deletes_the_file(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);
        $store = app(StepAttachmentStore::class);

        $stored = $store->add($step, UploadedFile::fake()->createWithContent('a.pdf', 'a'));

        $this->assertTrue($store->remove($step->fresh(), $stored['id']));
        Storage::disk('local')->assertMissing($stored['path']);
        $this->assertSame([], $step->fresh()->attachmentList());
    }

    public function test_an_attachment_can_be_uploaded_and_removed_over_the_api(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);
        $token = User::factory()->create()->createToken('t', ['write'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $response = $this->post(
            "/api/automations/{$step->automation_id}/steps/{$step->id}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('brief.pdf', 'BYTES')],
            $headers,
        )->assertStatus(201);

        $id = $response->json('data.attachments.0.id');
        $this->assertSame('brief.pdf', $response->json('data.attachments.0.filename'));

        $this->deleteJson(
            "/api/automations/{$step->automation_id}/steps/{$step->id}/attachments/{$id}",
            [],
            $headers,
        )->assertOk();

        $this->assertSame([], $step->fresh()->attachmentList());
    }

    public function test_the_dashboard_can_attach_a_file_to_a_saved_step(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);

        $this->actingAs(User::factory()->create());

        Livewire::test(Edit::class, ['automation' => $step->automation])
            ->set('newAttachment.0', UploadedFile::fake()->createWithContent('brief.pdf', 'BYTES'))
            ->call('uploadAttachment', 0)
            ->assertHasNoErrors();

        $this->assertCount(1, $step->fresh()->attachmentList());
        $this->assertSame('brief.pdf', $step->fresh()->attachmentList()[0]['filename']);
    }

    public function test_an_unsaved_step_says_to_save_first_rather_than_failing(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);

        $this->actingAs(User::factory()->create());

        // A file is stored against a step id, and a row that has not been
        // saved does not have one yet.
        Livewire::test(Edit::class, ['automation' => $step->automation])
            ->call('addStep')
            ->set('newAttachment.1', UploadedFile::fake()->createWithContent('brief.pdf', 'BYTES'))
            ->call('uploadAttachment', 1)
            ->assertHasErrors('newAttachment.1');
    }

    public function test_the_dashboard_can_remove_an_attachment(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);
        $stored = app(StepAttachmentStore::class)->add(
            $step,
            UploadedFile::fake()->createWithContent('brief.pdf', 'BYTES'),
        );

        $this->actingAs(User::factory()->create());

        Livewire::test(Edit::class, ['automation' => $step->automation])
            ->call('removeAttachment', 0, $stored['id']);

        $this->assertSame([], $step->fresh()->attachmentList());
        Storage::disk('local')->assertMissing($stored['path']);
    }

    public function test_a_disallowed_file_type_is_refused(): void
    {
        $step = SequenceStep::factory()->create(['position' => 1]);
        $token = User::factory()->create()->createToken('t', ['write'])->plainTextToken;

        $this->post(
            "/api/automations/{$step->automation_id}/steps/{$step->id}/attachments",
            ['file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')],
            ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
        )->assertStatus(422);
    }
}
