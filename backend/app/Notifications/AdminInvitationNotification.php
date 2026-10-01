<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation for a new administrator: a single-use link to choose their own password. Delivery uses the
 * configured mailer; locally that is the "log" mailer — no e-mail leaves the machine until a mail provider
 * is integrated.
 */
class AdminInvitationNotification extends Notification
{
    public function __construct(private readonly string $url, private readonly int $hours) {}

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
            ->subject(__('auth.invitation_subject'))
            ->line(__('auth.invitation_line', ['hours' => $this->hours]))
            ->action(__('auth.invitation_action'), $this->url)
            ->line(__('auth.invitation_ignore'));
    }

    public function url(): string
    {
        return $this->url;
    }
}
