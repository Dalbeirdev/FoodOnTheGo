<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesStaffNotice;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner of a restaurant or admin account that multi-factor authentication was turned on, turned
 * off, or reset by an administrator — so a change the owner did not make is noticed. The message carries
 * no link, no code and no secret: it only says what happened and what to do. It is written in every language
 * of `auth_security.notice_locales` (staff accounts have no language preference yet). Delivery uses the configured
 * mailer; locally that is the "log" mailer — no e-mail leaves the machine until a mail provider is
 * integrated.
 */
class MfaChangedNotification extends Notification
{
    use WritesStaffNotice;

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
        $change = $this->change;

        return $this->staffNotice('mfa', ["{$change}_line", "{$change}_next", 'not_you'], [], fn (string $locale): array => [
            'when' => $this->at->copy()->utc()->locale($locale)->translatedFormat('j M Y, H:i').' UTC',
        ], subjectKey: "{$change}_subject");
    }

    public function change(): string
    {
        return $this->change;
    }
}
