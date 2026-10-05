<?php

namespace App\Services\Journey\Providers;

use App\Exceptions\ProviderException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing of the Google adapters: a request with explicit timeouts and the API key header, and the
 * mapping of HTTP outcomes onto ProviderException kinds. The key never appears in a log or an exception.
 */
trait CallsGoogleApis
{
    /** @var array<string, mixed> */
    private array $config;

    private function key(): string
    {
        $key = (string) ($this->config['key'] ?? '');
        if ($key === '') {
            throw ProviderException::misconfigured($this->name(), 'no API key is configured');
        }

        return $key;
    }

    private function baseUrl(string $default): string
    {
        return rtrim((string) ($this->config['base_url'] ?? '') ?: $default, '/');
    }

    private function request(string $fieldMask): PendingRequest
    {
        $timeout = max(1, (int) ($this->config['timeout'] ?? 8));

        return Http::withHeaders(['X-Goog-Api-Key' => $this->key(), 'X-Goog-FieldMask' => $fieldMask])
            ->acceptJson()->timeout($timeout)->connectTimeout(min(3, $timeout));
    }

    /**
     * @param  Closure(): Response  $send
     * @param  Closure(int): ProviderException|null  $notFound  what a 404 means for this operation
     */
    private function call(Closure $send, ?Closure $notFound = null): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw ProviderException::timeout($this->name(), $e);
        }

        if ($response->successful()) {
            return $response;
        }
        $status = $response->status();

        throw match (true) {
            $status === 429 => ProviderException::rateLimited($this->name()),
            $status === 401 || $status === 403 => ProviderException::misconfigured($this->name(), 'the API key was refused'),
            $status === 404 && $notFound !== null => $notFound($status),
            $status === 400 => ProviderException::invalidLocation($this->name()),
            $status >= 500 => ProviderException::unavailable($this->name(), "HTTP {$status}"),
            default => ProviderException::unavailable($this->name(), "HTTP {$status}"),
        };
    }

    /**
     * Google durations are strings like "1234s".
     */
    private static function seconds(mixed $duration): int
    {
        return (int) round((float) rtrim((string) $duration, 's'));
    }
}
