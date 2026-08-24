<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Mailbox;
use App\Services\Gmail\GmailClientFactory;
use App\Services\Inbound\GmailInboxFetcher;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\History;
use Google\Service\Gmail\HistoryMessageAdded;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;
use Google\Service\Gmail\Profile;
use Google\Service\Gmail\Resource\UsersHistory;
use Google\Service\Gmail\Resource\UsersMessages;
use Google\Service\Gmail\Resource\Users as UsersResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Gmail's history lists messages that no longer exist — chiefly draft
 * autosaves, which live for seconds each while someone types an email by hand
 * in the mailbox. Fetching one threw, the whole poll aborted before saving its
 * position, and every later poll failed on the same message, so replies and
 * opt-outs stopped being detected entirely.
 */
class GmailInboxFetcherTest extends TestCase
{
    use RefreshDatabase;

    protected function historyMessage(string $id, array $labels = []): HistoryMessageAdded
    {
        $message = new GmailMessage;
        $message->setId($id);
        $message->setLabelIds($labels);

        $added = new HistoryMessageAdded;
        $added->setMessage($message);

        return $added;
    }

    protected function realMessage(string $id, string $from): GmailMessage
    {
        $header = new MessagePartHeader;
        $header->setName('From');
        $header->setValue($from);

        $payload = new MessagePart;
        $payload->setHeaders([$header]);
        $payload->setMimeType('text/plain');
        $payload->setBody(new Gmail\MessagePartBody);

        $message = new GmailMessage;
        $message->setId($id);
        $message->setThreadId('thread-1');
        $message->setPayload($payload);
        $message->setLabelIds(['INBOX']);
        $message->setSnippet('hello');

        return $message;
    }

    protected function fakeGmail(array $added, array $getBehaviour): Mailbox
    {
        $record = new History;
        $record->setId('100');
        $record->setMessagesAdded($added);

        $list = new ListHistoryResponse;
        $list->setHistory([$record]);

        $history = Mockery::mock(UsersHistory::class);
        $history->shouldReceive('listUsersHistory')->andReturn($list);

        $messages = Mockery::mock(UsersMessages::class);

        foreach ($getBehaviour as $id => $behaviour) {
            $expectation = $messages->shouldReceive('get')->with('me', $id, Mockery::any());
            $behaviour instanceof \Throwable
                ? $expectation->andThrow($behaviour)
                : $expectation->andReturn($behaviour);
        }

        $profile = new Profile;
        $profile->setHistoryId('999');

        $users = Mockery::mock(UsersResource::class);
        $users->shouldReceive('getProfile')->andReturn($profile);

        $gmail = Mockery::mock(Gmail::class);
        $gmail->users_history = $history;
        $gmail->users_messages = $messages;
        $gmail->users = $users;

        $factory = Mockery::mock(GmailClientFactory::class);
        $factory->shouldReceive('gmailFor')->andReturn($gmail);
        $this->app->instance(GmailClientFactory::class, $factory);

        return Mailbox::factory()->connected()->create(['gmail_history_id' => '50']);
    }

    protected function notFound(): GoogleServiceException
    {
        return new GoogleServiceException('Requested entity was not found.', 404);
    }

    public function test_a_missing_message_is_skipped_and_polling_continues(): void
    {
        $mailbox = $this->fakeGmail(
            added: [$this->historyMessage('gone'), $this->historyMessage('real')],
            getBehaviour: [
                'gone' => $this->notFound(),
                'real' => $this->realMessage('real', 'Jane <jane@example.com>'),
            ],
        );

        $emails = app(GmailInboxFetcher::class)->fetchNew($mailbox);

        $this->assertCount(1, $emails);
        $this->assertSame('jane@example.com', $emails[0]->fromEmail);
    }

    public function test_the_position_is_saved_even_when_a_message_is_missing(): void
    {
        $mailbox = $this->fakeGmail(
            added: [$this->historyMessage('gone')],
            getBehaviour: ['gone' => $this->notFound()],
        );

        app(GmailInboxFetcher::class)->fetchNew($mailbox);

        $this->assertSame('999', $mailbox->refresh()->gmail_history_id);
    }

    public function test_a_skipped_message_is_reported_with_its_id(): void
    {
        $mailbox = $this->fakeGmail(
            added: [$this->historyMessage('gone')],
            getBehaviour: ['gone' => $this->notFound()],
        );

        app(GmailInboxFetcher::class)->fetchNew($mailbox);

        $log = ActivityLog::query()->where('event', 'inbound_message_skipped')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('gone', $log->message);
    }

    public function test_draft_autosaves_are_never_fetched(): void
    {
        $mailbox = $this->fakeGmail(
            added: [$this->historyMessage('draft-1', ['DRAFT']), $this->historyMessage('real')],
            getBehaviour: ['real' => $this->realMessage('real', 'jane@example.com')],
        );

        $emails = app(GmailInboxFetcher::class)->fetchNew($mailbox);

        $this->assertCount(1, $emails);
        $this->assertSame('real', $emails[0]->gmailMessageId);
    }
}
