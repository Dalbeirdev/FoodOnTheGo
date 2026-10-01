<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The languages e-mails to restaurant and admin users can be written in: those listed in
 * `auth_security.notice_locales` that have a translation (lang/<code>/auth.php), in that order. Never empty —
 * the fallback language is always available.
 */
final class NoticeLocales
{
    /**
     * @return list<string>
     */
    public static function available(): array
    {
        $locales = array_values(array_unique(array_filter((array) config('auth_security.notice_locales'), fn ($l): bool => is_string($l) && Lang::has('auth.invitation_subject', $l, false))));

        return $locales === [] ? [(string) config('app.fallback_locale')] : $locales;
    }

    /**
     * The languages of one message: the person's own language when they chose one that is still available,
     * otherwise every available language.
     *
     * @return list<string>
     */
    public static function for(object $notifiable): array
    {
        $available = self::available();
        $preferred = $notifiable->preferred_locale ?? null;

        return is_string($preferred) && in_array($preferred, $available, true) ? [$preferred] : $available;
    }
}
