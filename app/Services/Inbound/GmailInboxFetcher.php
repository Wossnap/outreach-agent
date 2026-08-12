<?php

namespace App\Services\Inbound;

use App\Models\Mailbox;
use App\Services\Gmail\GmailClientFactory;
use Carbon\CarbonImmutable;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;

/**
 * Fetches new inbox messages for a mailbox via the Gmail history API, using
 * the stored history cursor. On an expired cursor (Gmail 404s history ids
 * older than ~a week) it falls back to a 2-day message list and resets.
 */
class GmailInboxFetcher
{
    public function __construct(
        protected GmailClientFactory $clientFactory,
    ) {}

    /**
     * @return array<InboundEmail>
     */
    public function fetchNew(Mailbox $mailbox): array
    {
        $gmail = $this->clientFactory->gmailFor($mailbox);

        $messageIds = $mailbox->gmail_history_id
            ? $this->idsFromHistory($gmail, $mailbox)
            : $this->idsFromRecentList($gmail);

        $emails = [];

        foreach (array_unique($messageIds) as $id) {
            $email = $this->fetchOne($gmail, $id);

            if ($email) {
                $emails[] = $email;
            }
        }

        // Advance the cursor to the mailbox's current historyId.
        $profile = $gmail->users->getProfile('me');
        $mailbox->update(['gmail_history_id' => $profile->getHistoryId(), 'last_polled_at' => now()]);

        return $emails;
    }

    /**
     * @return array<string>
     */
    protected function idsFromHistory(Gmail $gmail, Mailbox $mailbox): array
    {
        $ids = [];
        $pageToken = null;

        try {
            do {
                $params = [
                    'startHistoryId' => $mailbox->gmail_history_id,
                    'historyTypes' => 'messageAdded',
                ];

                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $history = $gmail->users_history->listUsersHistory('me', $params);

                foreach ($history->getHistory() ?? [] as $record) {
                    foreach ($record->getMessagesAdded() ?? [] as $added) {
                        $ids[] = $added->getMessage()->getId();
                    }
                }

                $pageToken = $history->getNextPageToken();
            } while ($pageToken);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                return $this->idsFromRecentList($gmail);
            }

            throw $e;
        }

        return $ids;
    }

    /**
     * @return array<string>
     */
    protected function idsFromRecentList(Gmail $gmail): array
    {
        $list = $gmail->users_messages->listUsersMessages('me', [
            'q' => 'newer_than:2d -in:sent',
            'maxResults' => 200,
        ]);

        return array_map(fn ($m) => $m->getId(), $list->getMessages() ?? []);
    }

    protected function fetchOne(Gmail $gmail, string $id): ?InboundEmail
    {
        $message = $gmail->users_messages->get('me', $id, ['format' => 'full']);
        $payload = $message->getPayload();

        if (! $payload) {
            return null;
        }

        $headers = [];

        foreach ($payload->getHeaders() ?? [] as $header) {
            $headers[mb_strtolower($header->getName())] = $header->getValue();
        }

        $fromEmail = $this->extractEmail($headers['from'] ?? '');

        if ($fromEmail === '') {
            return null;
        }

        return new InboundEmail(
            gmailMessageId: $message->getId(),
            gmailThreadId: $message->getThreadId(),
            fromEmail: $fromEmail,
            subject: $headers['subject'] ?? '',
            snippet: (string) $message->getSnippet(),
            bodyText: $this->extractText($payload),
            headers: $headers,
            receivedAt: $message->getInternalDate()
                ? CarbonImmutable::createFromTimestampMs((int) $message->getInternalDate())
                : null,
            labelIds: $message->getLabelIds() ?? [],
        );
    }

    protected function extractEmail(string $fromHeader): string
    {
        if (preg_match('/<([^>]+)>/', $fromHeader, $m)) {
            return mb_strtolower(trim($m[1]));
        }

        return mb_strtolower(trim($fromHeader));
    }

    protected function extractText(MessagePart $part): string
    {
        if ($part->getMimeType() === 'text/plain' && $part->getBody()?->getData()) {
            return $this->decodeBody($part->getBody()->getData());
        }

        foreach ($part->getParts() ?? [] as $child) {
            $text = $this->extractText($child);

            if ($text !== '') {
                return $text;
            }
        }

        // Bounces often carry the DSN in message/delivery-status parts; fall
        // back to any decodable body so the classifier can see it.
        if ($part->getBody()?->getData()) {
            return $this->decodeBody($part->getBody()->getData());
        }

        return '';
    }

    protected function decodeBody(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
