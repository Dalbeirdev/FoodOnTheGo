<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Free text written by restaurants (descriptions, pickup instructions, notes) is untrusted content.
 *
 * Strategy: it is stored and returned as PLAIN TEXT only. Control characters are removed, markup is refused
 * (not silently stripped — the writer is told), and every client must render the value as text, never as
 * HTML. Nothing in the API ever returns restaurant text as markup.
 */
final class PlainText
{
    /**
     * Cleans one value; null / blank becomes null.
     *
     * @throws ValidationException when the text contains markup or is not valid text
     */
    public static function clean(?string $value, string $field, bool $multiline = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $value);
        // Control characters go (new lines stay in multi-line fields); joiners used by scripts and emoji are kept.
        $text = $multiline
            ? preg_replace(['/[^\P{Cc}\n]/u', '/[^\S\n]+/u', '/\n{3,}/'], ['', ' ', "\n\n"], $text)
            : preg_replace(['/\p{Cc}/u', '/\s+/u'], [' ', ' '], $text);

        if ($text === null) {
            throw ValidationException::withMessages([$field => ['This text contains characters that cannot be stored.']]);
        }
        if (preg_match('/<[a-zA-Z!\/?]/', $text) === 1) {
            throw ValidationException::withMessages([$field => ['Formatting and HTML are not allowed here — plain text only.']]);
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
