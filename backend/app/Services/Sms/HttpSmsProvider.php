<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsProvider;
use App\Exceptions\SmsDeliveryException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared plumbing of the real SMS drivers: a timeout, one retry on a connection failure, and error mapping
 * that never lets a URL, a credential or a message body reach an exception message or a log line
 * (HTTP client exceptions would otherwise carry the full request URL — which for some providers holds the key).
 */
abstract class HttpSmsProvider implements SmsProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected readonly array $config, protected readonly int $timeout = 10) {}

    protected function http(): PendingRequest
    {
        // One retry, and only when the provider could not be reached: an answered request is never sent twice.
        return Http::timeout($this->timeout)->connectTimeout(5)
            ->retry(2, 300, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
            ->acceptJson();
    }

    /**
     * Value of a required configuration key, or a configuration failure naming the key (never its value).
     */
    protected function required(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw SmsDeliveryException::notConfigured($this->name(), $key);
        }

        return $value;
    }

    /**
     * Runs the provider call and converts anything that goes wrong into a safe SmsDeliveryException.
     *
     * @param  Closure(): Response  $call
     */
    protected function send(Closure $call): Response
    {
        try {
            $response = $call();
        } catch (SmsDeliveryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsDeliveryException($this->name(), 'provider unreachable ('.class_basename($e).')');
        }

        if ($response->failed()) {
            throw new SmsDeliveryException($this->name(), 'provider answered HTTP '.$response->status());
        }

        return $response;
    }

    /**
     * Digits only, without the leading "+": the form most Indian gateways expect (919876543210).
     */
    protected function digits(string $phone): string
    {
        return ltrim($phone, '+');
    }
}
