<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Single place where exceptions become API responses. Every error has the same envelope:
 *
 *   {"error": {"code": "...", "message": "...", "details": {...}, "request_id": "..."}}
 *
 * Messages are customer-safe and written in the language of the request (SetApiLocale): a message is looked up
 * by its English text in lang/<code>.json and stays English when there is no translation. SQL, stack traces and paths never leave the server unless APP_DEBUG is on,
 * and then only under a separate "debug" key.
 */
final class ApiExceptionRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        419 => 'session_expired',
        429 => 'rate_limited',
        503 => 'service_unavailable',
    ];

    private const MESSAGES = [
        400 => 'The request could not be understood.',
        401 => 'Authentication is required.',
        403 => 'You are not allowed to do this.',
        404 => 'The requested resource was not found.',
        405 => 'This method is not allowed for the resource.',
        409 => 'The request conflicts with the current state.',
        419 => 'The session has expired.',
        429 => 'Too many requests. Please try again shortly.',
        503 => 'The service is temporarily unavailable.',
    ];

    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = $this->describe($e);

        $error = ['code' => $code, 'message' => __($message)];
        if ($details !== []) {
            if (is_array($details['fields'] ?? null)) {
                $details['fields'] = array_map(fn ($messages) => array_map(fn ($text) => is_string($text) ? __($text) : $text, (array) $messages), $details['fields']);
            }
            $error['details'] = $details;
        }
        if (($requestId = Context::get('request_id')) !== null) {
            $error['request_id'] = $requestId;
        }

        $body = ['error' => $error];
        if (config('app.debug') && $status >= 500) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return response()->json($body, $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: array<string, mixed>}
     */
    private function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details, []],
            $e instanceof ValidationException => [$e->status, 'validation_failed', 'The submitted data is invalid.', ['fields' => $e->errors()], []],
            $e instanceof AuthenticationException => [401, self::CODES[401], self::MESSAGES[401], [], []],
            $e instanceof ThrottleRequestsException => [429, self::CODES[429], self::MESSAGES[429], $this->retryAfter($e), $e->getHeaders()],
            $e instanceof HttpExceptionInterface => $this->describeHttp($e),
            default => [500, 'internal_error', 'Something went wrong on our side.', [], []],
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: array<string, mixed>}
     */
    private function describeHttp(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();

        if ($status >= 500 && $status !== 503) {
            return [$status, 'internal_error', 'Something went wrong on our side.', [], []];
        }

        return [
            $status,
            self::CODES[$status] ?? 'http_'.$status,
            self::MESSAGES[$status] ?? 'The request could not be completed.',
            [],
            $e->getHeaders(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function retryAfter(ThrottleRequestsException $e): array
    {
        $seconds = $e->getHeaders()['Retry-After'] ?? null;

        return $seconds === null ? [] : ['retry_after_seconds' => (int) $seconds];
    }
}
