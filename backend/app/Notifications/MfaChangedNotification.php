<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner of a restaurant or admin account that multi-factor authentication was turned on, turned
 * off, or reset by an administrator — so a change the owner did not make is noticed. The message carries
 * no link, no code and no secret: it only says what happened and what to do. Delivery uses the configured
 * mailer; locally that is the "log" mailer — no e-mail leaves the machine until a mail provider is
 * integrated.
 */
class MfaChangedNotification extends Notification
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const RESET_BY_ADMINISTRATOR = 'reset';

    public function __construct(private readonly string $change, private readonly CarbonInterface $at) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->at->copy()->utc()->format('j M Y, H:i').' UTC';

        $subject = __("auth.mfa_{$this->change}_subject");

        // Own plain layout: the framework's default mail layout puts a link to the application in its header.
        return (new MailMessage)
            ->subject($subject)
            ->view(['html' => 'mail.security-notice', 'text' => 'mail.security-notice-text'], [
                'subject' => $subject,
                'lines' => [__("auth.mfa_{$this->change}_line", ['when' => $when]), __("auth.mfa_{$this->change}_next"), __('auth.mfa_not_you')],
            ]);
    }

    public function change(): string
    {
        return $this->change;
    }
}
