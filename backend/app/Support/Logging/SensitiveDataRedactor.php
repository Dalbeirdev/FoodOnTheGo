<?php

namespace App\Support\Logging;

/**
 * Masks secrets before anything is written to a log: values of sensitive keys at any depth, and
 * bearer tokens that appear inside free text.
 *
 * Module 39 adds: the SQL text and key values of database errors, phone numbers and e-mail addresses in log MESSAGES,
 * and — outside local development and the test suite — the tokens of one-time links.
 */
final class SensitiveDataRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * @param  list<string>  $sensitiveKeys  lower-case fragments; a key is sensitive when it contains one.
     *                                       A fragment written "=name" must equal the key exactly.
     * @param  bool  $maskOneTimeLinks  true outside local / testing: the token of a reset or invitation link is masked
     *                                  (locally the development mail transport is the log, and its links stay readable).
     */
    public function __construct(private readonly array $sensitiveKeys, private readonly bool $maskOneTimeLinks = false) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->redactText($value);
            }
        }

        return $data;
    }

    public function redactText(string $text): string
    {
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._|~+\/=-]+/i', 'Bearer '.self::MASK, $text);

        // A database error carries the statement with its bound values and the conflicting key
        // ("Key (phone_e164)=(+91…)"): the SQL text and the key values are masked; the SQLSTATE stays readable.
        $text = (string) preg_replace('/\bSQL: .*/s', 'SQL: '.self::MASK.')', $text);
        $text = (string) preg_replace('/(Key \([^)]*\)=)\([^)]*\)/', '$1('.self::MASK.')', $text);

        if ($this->maskOneTimeLinks) {
            $text = (string) preg_replace('/(#|[?&](?:token|code|signature)=)[A-Za-z0-9._~-]{24,}/i', '$1'.self::MASK, $text);
        }

        return $text;
    }

    /**
     * A log MESSAGE is free text written by code (often an exception's message): besides the above, phone numbers and
     * e-mail addresses in it are masked. (A structured context field that names its subject keeps its value.)
     */
    public function redactMessage(string $text): string
    {
        $text = $this->redactText($text);
        $text = (string) preg_replace('/\+\d{4,11}(\d{4})\b/', '+********$1', $text);

        return (string) preg_replace('/\b[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.[A-Za-z0-9.-]+)\b/', '***@$1', $text);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower(str_replace(['-', ' '], '_', $key));

        foreach ($this->sensitiveKeys as $fragment) {
            $exact = str_starts_with($fragment, '=');
            if ($exact ? $normalised === substr($fragment, 1) : str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
