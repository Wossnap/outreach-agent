<?php

namespace App\Services\Gmail;

use App\Models\ActivityLog;
use App\Models\Mailbox;
use Google\Client;
use Google\Service\Gmail;
use RuntimeException;

class GmailClientFactory
{
    public const SCOPES = [
        Gmail::GMAIL_SEND,
        Gmail::GMAIL_READONLY,
        'openid',
        'email',
    ];

    /**
     * Bare client configured for the app (used for the OAuth dance).
     */
    public function bare(): Client
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /**
     * Authorized Gmail service for a connected mailbox, refreshing the access
     * token when it is missing or within 5 minutes of expiry.
     */
    public function gmailFor(Mailbox $mailbox): Gmail
    {
        return new Gmail($this->authorizedClientFor($mailbox));
    }

    public function authorizedClientFor(Mailbox $mailbox): Client
    {
        if (! $mailbox->google_refresh_token) {
            throw new RuntimeException("Mailbox {$mailbox->email} has no refresh token — reconnect it.");
        }

        $client = $this->bare();

        $needsRefresh = ! $mailbox->google_access_token
            || ! $mailbox->google_token_expires_at
            || $mailbox->google_token_expires_at->lessThan(now()->addMinutes(5));

        if (! $needsRefresh) {
            $client->setAccessToken(['access_token' => $mailbox->google_access_token]);

            return $client;
        }

        $token = $client->fetchAccessTokenWithRefreshToken($mailbox->google_refresh_token);

        if (isset($token['error'])) {
            if (($token['error'] ?? '') === 'invalid_grant') {
                $mailbox->update(['status' => Mailbox::STATUS_DISCONNECTED, 'paused_reason' => 'Google refresh token revoked/expired']);
                ActivityLog::record(
                    event: 'token_refresh_failed',
                    message: "Mailbox {$mailbox->email} disconnected: Google refresh token is no longer valid.",
                    level: ActivityLog::LEVEL_ERROR,
                    subject: $mailbox,
                );
            }

            throw new RuntimeException("Token refresh failed for {$mailbox->email}: ".json_encode($token));
        }

        $mailbox->update([
            'google_access_token' => $token['access_token'],
            'google_token_expires_at' => now()->addSeconds($token['expires_in'] ?? 3600),
        ]);

        return $client;
    }
}
