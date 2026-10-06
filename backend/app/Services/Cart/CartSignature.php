<?php

namespace App\Services\Cart;

use App\Exceptions\ApiException;
use Normalizer;

/**
 * When are two cart lines "the same thing"? When the menu item, the selected options and the special
 * instructions are the same — decided from ids, never from display names:
 *
 *   sha256( item id | sorted option ids | normalized instructions )
 *
 * The order in which options were ticked does not matter (ids are sorted). Instructions are compared after
 * Unicode normalization, trimming, collapsing white space and lower-casing, so "No onions" and " no  onions "
 * are one line while "No onions" and "Extra onions" stay two.
 */
final class CartSignature
{
    /**
     * @param  list<string>  $optionIds  public ids of the selected options
     */
    public static function of(string $itemId, array $optionIds, ?string $instructions): string
    {
        $options = array_values(array_unique(array_map('strval', $optionIds)));
        sort($options, SORT_STRING);

        return hash('sha256', $itemId.'|'.implode(',', $options).'|'.self::comparable($instructions));
    }

    /**
     * Special instructions are free text from a customer: untrusted, stored and returned as plain text only.
     * Control characters go, white space is collapsed, the length is bounded. Markup is NOT interpreted anywhere
     * — a client renders the value as text — so it is kept as written rather than refused.
     *
     * @throws ApiException 422 when the text is too long or is not valid text
     */
    public static function cleanInstructions(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid('The special instructions contain characters that cannot be stored.', 'invalid_text');
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        $text = preg_replace(['/\p{Cc}+/u', '/\s+/u'], [' ', ' '], $normalized === false ? $value : $normalized);
        $text = trim((string) $text);
        $max = (int) config('menu.limits.instructions');
        if (mb_strlen($text) > $max) {
            throw self::invalid('Special instructions can be at most '.$max.' characters.', 'max:'.$max);
        }

        return $text === '' ? null : $text;
    }

    private static function comparable(?string $instructions): string
    {
        return $instructions === null ? '' : mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $instructions)));
    }

    private static function invalid(string $message, string $rule): ApiException
    {
        return new ApiException(422, 'validation_failed', $message, ['fields' => ['special_instructions' => [$rule]]]);
    }
}
