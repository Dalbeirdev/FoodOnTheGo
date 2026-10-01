<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesStaffNotice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset link for a restaurant or admin user. Delivery uses the configured mailer; locally that is
 * the "log" mailer — no e-mail leaves the machine until a mail provider is integrated. Written in every
 * configured language (see WritesStaffNotice).
 */
class CredentialResetNotification extends Notification
{
    use WritesStaffNotice;

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
        return $this->staffNotice($notifiable, 'password_reset', ['line'], ['ignore'], fn (): array => ['minutes' => $this->minutes], $this->url);
    }
}
