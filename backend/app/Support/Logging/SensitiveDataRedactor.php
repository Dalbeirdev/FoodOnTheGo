<?php

namespace App\Support\Logging;

/**
 * Masks secrets before anything is written to a log: values of sensitive keys at any depth, and
 * bearer tokens that appear inside free text.
 */
final class SensitiveDataRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * @param  list<string>  $sensitiveKeys  lower-case fragments; a key is sensitive when it contains one
     */
    public function __construct(private readonly array $sensitiveKeys) {}

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
        return (string) preg_replace('/\bBearer\s+[A-Za-z0-9._|~+\/=-]+/i', 'Bearer '.self::MASK, $text);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalised = strtolower(str_replace(['-', ' '], '_', $key));

        foreach ($this->sensitiveKeys as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
