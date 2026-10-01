<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

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
        // Own plain layout: the framework's default mail layout puts a link to the application in its header.
        $sections = $this->sections();

        return (new MailMessage)
            ->subject($sections[0]['subject'])
            ->view(['html' => 'mail.security-notice', 'text' => 'mail.security-notice-text'], ['subject' => $sections[0]['subject'], 'sections' => $sections]);
    }

    /**
     * The notice once per configured language that has a translation; always at least the fallback language.
     *
     * @return list<array{locale: string, subject: string, lines: list<string>}>
     */
    private function sections(): array
    {
        $locales = array_values(array_filter((array) config('auth_security.notice_locales'), fn (string $l): bool => Lang::has('auth.mfa_not_you', $l, false)));
        $locales = $locales === [] ? [(string) config('app.fallback_locale')] : $locales;

        return array_map(function (string $locale): array {
            $when = $this->at->copy()->utc()->locale($locale)->translatedFormat('j M Y, H:i').' UTC';

            return [
                'locale' => $locale,
                'subject' => __("auth.mfa_{$this->change}_subject", [], $locale),
                'lines' => [__("auth.mfa_{$this->change}_line", ['when' => $when], $locale), __("auth.mfa_{$this->change}_next", [], $locale), __('auth.mfa_not_you', [], $locale)],
            ];
        }, $locales);
    }

    public function change(): string
    {
        return $this->change;
    }
}
