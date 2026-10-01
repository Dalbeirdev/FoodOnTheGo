<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset link for a restaurant or admin user. Delivery uses the configured mailer; locally that is
 * the "log" mailer — no e-mail leaves the machine until a mail provider is integrated.
 */
class CredentialResetNotification extends Notification
{
    public function __construct(private readonly string $url, private readonly int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.password_reset_subject'))
            ->line(__('auth.password_reset_line', ['minutes' => $this->minutes]))
            ->action(__('auth.password_reset_action'), $this->url)
            ->line(__('auth.password_reset_ignore'));
    }
}
