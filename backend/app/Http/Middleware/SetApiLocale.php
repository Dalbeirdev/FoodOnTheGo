<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the messages in API answers (errors, validation texts, confirmations): the best match between
 * the request's Accept-Language header and the languages that have a translation (lang/<code>.json), else
 * the application's default. Our own clients send the language of the screen the person is looking at.
 *
 * Only texts change. Codes, field names, numbers, dates and money in an answer never depend on the language.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Set on every request, also back to the default: a long-running worker must not keep the previous request's language.
        $locale = $request->headers->has('Accept-Language') ? $request->getPreferredLanguage(self::available()) : null;
        app()->setLocale(is_string($locale) && in_array($locale, self::available(), true) ? $locale : self::default());

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $response->setVary('Accept-Language', false);

        return $response;
    }

    /**
     * The language of an answer when the request asks for none we have. Not `app.locale`: setting the locale of a
     * request changes that value.
     */
    private static function default(): string
    {
        return (string) config('app.fallback_locale');
    }

    /**
     * The default language first, then every language with a message file.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $codes = array_map(fn (string $file): string => basename($file, '.json'), glob(lang_path('*.json')) ?: []);

        return array_values(array_unique([self::default(), ...$codes]));
    }
}
