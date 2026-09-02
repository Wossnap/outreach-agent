<?php

namespace App\Services\Gmail;

use App\Models\ActivityLog;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Deliberately disconnects a mailbox: tells Google to forget us, discards the
 * stored credentials, and frees anything queued to send through it.
 *
 * The dashboard and the API both call this, so a mailbox cannot end up
 * half-disconnected depending on which route was used.
 *
 * Reconnecting is the ordinary "Connect Google mailbox" flow. It asks Google
 * for consent again, so a new refresh token is issued and the same row is
 * reused rather than duplicated.
 */
class MailboxDisconnector
{
    /**
     * @return array{released: int, revoked_with_google: bool}
     */
    public function disconnect(Mailbox $mailbox, string $reason): array
    {
        $revoked = $this->revokeWithGoogle($mailbox);
        $released = $this->releaseQueuedMessages($mailbox);

        $mailbox->update([
            'status' => Mailbox::STATUS_DISCONNECTED,
            'paused_reason' => $reason,
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
            'google_scopes' => null,
            // The inbox bookmark refers to a history this account will no
            // longer share with us. Keeping it would make a later reconnect
            // ask Gmail for changes since a point it no longer recognises.
            'gmail_history_id' => null,
        ]);

        ActivityLog::record(
            event: 'mailbox_disconnected',
            message: "Disconnected {$mailbox->email}: {$reason}",
            level: ActivityLog::LEVEL_WARNING,
            subject: $mailbox,
            context: [
                'mailbox_id' => $mailbox->id,
                'released_messages' => $released,
                'revoked_with_google' => $revoked,
            ],
        );

        return ['released' => $released, 'revoked_with_google' => $revoked];
    }

    /**
     * Ask Google to invalidate the token.
     *
     * Best effort on purpose. If Google is unreachable, or the grant is already
     * gone, we still discard our copy: leaving the mailbox connected here
     * because a remote call failed would be the worse outcome.
     */
    protected function revokeWithGoogle(Mailbox $mailbox): bool
    {
        $token = $mailbox->google_refresh_token ?: $mailbox->google_access_token;

        if (! $token) {
            return false;
        }

        try {
            return Http::asForm()
                ->timeout(10)
                ->post('https://oauth2.googleapis.com/revoke', ['token' => $token])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Unpin anything queued on this mailbox so another one can take it.
     *
     * Without this, an email already given a send slot keeps pointing at a
     * mailbox that can no longer send, and fails when its slot arrives. Put
     * back to approved and unpinned, it is picked up by the recovery sweep and
     * reassigned, or waits harmlessly until a mailbox exists.
     *
     * Messages already handed to Gmail are left alone; they are mid-flight.
     */
    protected function releaseQueuedMessages(Mailbox $mailbox): int
    {
        return Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->whereIn('status', [Message::STATUS_APPROVED, Message::STATUS_SCHEDULED])
            ->update([
                'status' => Message::STATUS_APPROVED,
                'mailbox_id' => null,
                'scheduled_at' => null,
                'updated_at' => now(),
            ]);
    }
}
