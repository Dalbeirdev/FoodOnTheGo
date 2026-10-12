<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * CSV for downloads (Module 36) — with the protection every export of this application uses.
 *
 * FORMULA INJECTION: a spreadsheet runs a cell that begins with = + - @ (or a tab / carriage return) as a formula.
 * Such a cell is written with a leading apostrophe, which a spreadsheet shows as text. A plain number — also a
 * negative one — is left as it is, so that amounts stay numbers. Everything is quoted, and quotes are doubled.
 */
final class Csv
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '""';
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $text = (string) $value;
        if (preg_match('/\A-?\d+(\.\d+)?\z/', $text) !== 1 && preg_match('/\A[=+\-@\t\r]/', $text) === 1) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    public static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells))."\r\n";
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function response(string $filename, array $header, iterable $rows): Response
    {
        // The byte order mark makes a spreadsheet read the file as UTF-8.
        $body = "\xEF\xBB\xBF".self::line($header);
        foreach ($rows as $row) {
            $body .= self::line($row);
        }

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '-', $filename).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Minor units as a decimal string of the currency ("45000" INR → "450.00"), for people who read the file. */
    public static function amount(int $minor, string $currency): string
    {
        $digits = (new Money(0, $currency))->fractionDigits();
        if ($digits === 0) {
            return (string) $minor;
        }
        $sign = $minor < 0 ? '-' : '';
        $abs = (string) abs($minor);
        $abs = str_pad($abs, $digits + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$digits).'.'.substr($abs, -$digits);
    }
}
