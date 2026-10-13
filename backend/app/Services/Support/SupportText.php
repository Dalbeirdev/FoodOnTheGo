<?php

namespace App\Services\Support;

use Illuminate\Validation\ValidationException;

/**
 * Text written into a support case by a customer, a restaurant or an administrator is UNTRUSTED PLAIN TEXT.
 *
 * It is stored as written — in any language, with whatever a person pasted, angle brackets included, because a
 * message to support must never be turned away for containing "<" — minus control characters, and it is only ever
 * sent as a JSON string and rendered as text. Nothing in the API or in any client treats it as markup, so a stored
 * "<script>" is eleven harmless characters.
 */
final class SupportText
{
    /**
     * @throws ValidationException when nothing readable is left or the text is too long
     */
    public static function clean(mixed $value, string $field, int $max, bool $multiline = true): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages([$field => ['This must be text.']]);
        }
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $value);
        $text = $multiline
            ? preg_replace(['/[^\P{Cc}\n]/u', '/[^\S\n]+/u', '/\n{3,}/'], ['', ' ', "\n\n"], $text)
            : preg_replace(['/\p{Cc}/u', '/\s+/u'], [' ', ' '], $text);
        $text = trim((string) $text);
        if ($text === '') {
            throw ValidationException::withMessages([$field => ['Please write something.']]);
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => ['This is too long: at most '.$max.' characters.']]);
        }

        return $text;
    }
}
