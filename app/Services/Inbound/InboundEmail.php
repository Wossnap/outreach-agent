<?php

namespace App\Services\Inbound;

use Carbon\CarbonImmutable;

/**
 * Provider-agnostic shape of a fetched inbox message, so classification and
 * matching logic can be tested without the Gmail API.
 */
class InboundEmail
{
    public function __construct(
        public string $gmailMessageId,
        public ?string $gmailThreadId,
        public string $fromEmail,
        public string $subject,
        public string $snippet,
        public string $bodyText,
        /** @var array<string, string> lower-cased header name => value */
        public array $headers = [],
        public ?CarbonImmutable $receivedAt = null,
        /** @var array<string> */
        public array $labelIds = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    public function isSentByUs(): bool
    {
        return in_array('SENT', $this->labelIds, true);
    }
}
