<?php

namespace App\Notifications\Concerns;

use App\Support\NoticeLocales;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * E-mails to restaurant and admin users (invitation, password reset, MFA changed) share one plain layout and
 * one language rule (NoticeLocales): a person who chose a language gets the message in that language; without
 * a choice it carries every available language, in the configured order, with the subject in the first.
 *
 * The layout is our own (`mail.security-notice`): the framework's default mail layout adds a header link to
 * the application address and English-only greeting lines. A message contains a link only when it is the
 * purpose of the message (choose a password); a notice about a change contains none.
 */
trait WritesStaffNotice
{
    /**
     * @param  string  $prefix  translation key prefix in lang/<locale>/auth.php, e.g. "invitation"
     * @param  list<string>  $before  key suffixes of the paragraphs above the link
     * @param  list<string>  $after  key suffixes of the paragraphs below the link
     * @param  callable(string): array<string, string|int>  $replace  placeholders for one language
     * @param  string  $subjectKey  key suffix of the subject
     */
    protected function staffNotice(object $notifiable, string $prefix, array $before, array $after, callable $replace, ?string $url = null, string $subjectKey = 'subject'): MailMessage
    {
        $locales = NoticeLocales::for($notifiable);

        $sections = array_map(function (string $locale) use ($prefix, $before, $after, $replace, $url, $subjectKey): array {
            $text = fn (string $suffix): string => __("auth.{$prefix}_{$suffix}", $replace($locale), $locale);

            return [
                'locale' => $locale,
                'subject' => $text($subjectKey),
                'lines' => array_map($text, $before),
                'action' => $url === null ? null : $text('action'),
                'after' => array_map($text, $after),
            ];
        }, $locales);

        $mail = (new MailMessage)->subject($sections[0]['subject'])
            ->view(['html' => 'mail.security-notice', 'text' => 'mail.security-notice-text'], ['subject' => $sections[0]['subject'], 'sections' => $sections, 'url' => $url]);

        return $url === null ? $mail : $mail->action($sections[0]['action'], $url);
    }
}
