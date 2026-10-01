<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesStaffNotice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation for a new administrator: a single-use link to choose their own password. Delivery uses the
 * configured mailer; locally that is the "log" mailer — no e-mail leaves the machine until a mail provider
 * is integrated. Written in every configured language (see WritesStaffNotice).
 */
class AdminInvitationNotification extends Notification
{
    use WritesStaffNotice;

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
        return $this->staffNotice('invitation', ['line'], ['ignore'], fn (): array => ['hours' => $this->hours], $this->url);
    }

    public function url(): string
    {
        return $this->url;
    }
}
