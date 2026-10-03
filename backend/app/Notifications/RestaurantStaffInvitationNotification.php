<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to join a restaurant's staff: a single-use link. A new account chooses its password with it;
 * an existing account simply joins. Delivery uses the configured mailer; locally that is the "log" mailer —
 * no e-mail leaves the machine until a mail provider is integrated.
 */
class RestaurantStaffInvitationNotification extends Notification
{
    public function __construct(private readonly string $url, private readonly int $hours, private readonly string $restaurant, private readonly bool $newAccount) {}

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
            ->subject(__('auth.staff_invitation_subject', ['restaurant' => $this->restaurant]))
            ->line(__($this->newAccount ? 'auth.staff_invitation_line_new' : 'auth.staff_invitation_line_existing', ['restaurant' => $this->restaurant, 'hours' => $this->hours]))
            ->action(__($this->newAccount ? 'auth.invitation_action' : 'auth.staff_invitation_action_existing'), $this->url)
            ->line(__('auth.staff_invitation_ignore'));
    }

    public function url(): string
    {
        return $this->url;
    }
}
