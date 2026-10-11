<?php

namespace App\Services\Review;

/**
 * Review text is plain text in any language. It is stored as written (normalised whitespace, no control characters),
 * sent as JSON and rendered as text by every client — it is never treated as markup anywhere. Anything that looks like
 * a tag is refused at the door so nobody is tempted to render it later.
 */
final class ReviewText
{
    /**
     * Trimmed text with normalised line breaks and without control characters; null when nothing is left.
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = str_replace("\t", ' ', $text);
        // Control characters only (Cc). Format characters such as the zero-width joiner stay: scripts and emoji need them.
        $text = (string) preg_replace('/[^\P{Cc}\n]+/u', '', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    public static function length(?string $text): int
    {
        return $text === null ? 0 : mb_strlen($text, 'UTF-8');
    }

    /**
     * Something shaped like an HTML / XML tag, a comment or an entity-encoded tag. "<3" and "5 > 4" are not.
     */
    public static function hasMarkup(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        return preg_match('/<\s*\/?\s*[a-zA-Z!?][^>]*>|&lt;\s*\/?\s*[a-zA-Z!]|&#x?[0-9a-fA-F]+;|javascript\s*:/iu', $text) === 1;
    }

    public static function hash(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return hash('sha256', mb_strtolower((string) preg_replace('/\s+/u', ' ', $text), 'UTF-8'));
    }
}
