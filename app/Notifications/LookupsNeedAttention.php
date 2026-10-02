<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells somebody that leads are, or soon will be, waiting on a finder or
 * checker that cannot work.
 *
 * Sent only when something new goes wrong, never on every check, and lists
 * everything that is wrong at the time so one email is the whole picture.
 */
class LookupsNeedAttention extends Notification
{
    use Queueable;

    /**
     * @param  array<string, string>  $alerts  everything currently wrong
     */
    public function __construct(public array $alerts) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Email lookups need attention')
            ->line('New leads will wait to retry until this is sorted:');

        foreach ($this->alerts as $alert) {
            $mail->line('- '.$alert);
        }

        return $mail
            ->action('Open Email waterfall', route('settings.waterfall'))
            ->line('Once a provider is topped up it is switched back on by itself within the hour, and waiting leads are sent through again.');
    }
}
