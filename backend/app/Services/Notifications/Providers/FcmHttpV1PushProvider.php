<?php

namespace App\Services\Notifications\Providers;

use App\Contracts\Notifications\PushNotificationProvider;
use App\Enums\NotificationErrorCategory;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging over the HTTP v1 API (documentation reviewed 2026-10-09: the legacy HTTP / batch APIs were shut
 * down in June 2024; v1 has no batch endpoint, one request = one device). Authentication: an OAuth2 bearer token minted
 * from the service-account JSON with the official google/auth library (scope firebase.messaging), cached until shortly
 * before it expires. Errors are mapped to the normalised categories exactly as the FCM error-code page prescribes:
 * UNREGISTERED (404) → the token is dead; INVALID_ARGUMENT (400) → never retried, the token removed when the detail names
 * it; SENDER_ID_MISMATCH (403) → the token belongs to another project (dead for us); QUOTA_EXCEEDED (429) → back off at
 * least a minute; UNAVAILABLE (503) / INTERNAL (500) → retry with backoff honouring Retry-After; an OAuth failure → our
 * configuration, never a retry storm. No credential, token or raw provider body is logged.
 */
final class FcmHttpV1PushProvider implements PushNotificationProvider
{
    private const TOKEN_CACHE_KEY = 'notifications:fcm:access-token';

    private ?ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http;
    }

    public function name(): string
    {
        return 'fcm';
    }

    public function configured(): bool
    {
        $path = (string) config('notifications.fcm.credentials');

        return $path !== '' && is_readable($path) && (string) $this->projectId() !== '';
    }

    public function send(PushMessage $message): ProviderResult
    {
        if (! $this->configured()) {
            return ProviderResult::failed(NotificationErrorCategory::AuthConfiguration, 'NOT_CONFIGURED', 'Firebase is not configured on this server (PENDING FIREBASE CONFIGURATION).');
        }
        $bytes = $message->payloadBytes();
        if ($bytes > (int) config('notifications.fcm.max_payload_bytes')) {
            return ProviderResult::failed(NotificationErrorCategory::Permanent, 'MESSAGE_TOO_BIG', 'The message exceeds the provider payload limit.');
        }

        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $e) {
            Log::warning('notifications.fcm.auth_failed', ['class' => $e::class]);

            return ProviderResult::failed(NotificationErrorCategory::AuthConfiguration, 'OAUTH_TOKEN_FAILED', 'The server could not authenticate with Firebase.');
        }

        $url = rtrim((string) config('notifications.fcm.endpoint'), '/').'/projects/'.$this->projectId().'/messages:send';
        $body = ['message' => $message->toFcm(true)];
        $restricted = (string) config('notifications.fcm.restricted_package_name');
        if ($restricted !== '') {
            $body['message']['android']['restricted_package_name'] = $restricted;
        }

        try {
            $response = $this->client()->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer '.$accessToken, 'Accept' => 'application/json'],
                'json' => $body,
                'timeout' => (float) config('notifications.fcm.timeout_seconds'),
                'http_errors' => false,
            ]);
        } catch (ConnectException) {
            return ProviderResult::failed(NotificationErrorCategory::Transient, 'CONNECTION_FAILED', 'Could not reach the provider.');
        } catch (RequestException $e) {
            return ProviderResult::failed(NotificationErrorCategory::Transient, 'REQUEST_FAILED', 'The request to the provider failed before an answer.');
        } catch (Throwable $e) {
            return ProviderResult::failed(NotificationErrorCategory::Unknown, 'CLIENT_ERROR', 'The provider call failed unexpectedly.');
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300) {
            $name = is_array($json) ? (string) ($json['name'] ?? '') : '';

            return ProviderResult::sent($name !== '' ? $name : null);
        }

        return $this->mapError($status, is_array($json) ? $json : [], $response->getHeaderLine('Retry-After'));
    }

    public function health(): array
    {
        if (! $this->configured()) {
            return ['status' => 'UNAVAILABLE', 'driver' => 'fcm', 'detail' => 'PENDING FIREBASE CONFIGURATION — FIREBASE_PROJECT_ID / FIREBASE_CREDENTIALS are not set on this server; no push can be sent.', 'latency_ms' => null];
        }
        $started = microtime(true);
        try {
            $this->accessToken();

            return ['status' => 'OPERATIONAL', 'driver' => 'fcm', 'detail' => 'Service-account token minted for project '.$this->projectId().'.', 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
        } catch (Throwable $e) {
            return ['status' => 'DEGRADED', 'driver' => 'fcm', 'detail' => 'The server could not authenticate with Firebase ('.$e::class.').', 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    public function mapError(int $status, array $json, string $retryAfter = ''): ProviderResult
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $googleStatus = (string) ($error['status'] ?? '');
        $fcmCode = '';
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && str_ends_with((string) ($detail['@type'] ?? ''), 'google.firebase.fcm.v1.FcmError')) {
                $fcmCode = (string) ($detail['errorCode'] ?? '');
            }
        }
        $code = $fcmCode !== '' ? $fcmCode : ($googleStatus !== '' ? $googleStatus : 'HTTP_'.$status);
        $message = mb_substr((string) ($error['message'] ?? ''), 0, 200);
        $retry = ctype_digit($retryAfter) ? (int) $retryAfter : null;

        return match (true) {
            $status === 404 || $fcmCode === 'UNREGISTERED' => ProviderResult::failed(NotificationErrorCategory::InvalidDestination, $code, 'The device token is no longer registered.'),
            $fcmCode === 'SENDER_ID_MISMATCH' => ProviderResult::failed(NotificationErrorCategory::InvalidDestination, $code, 'The device token belongs to another sender.'),
            $status === 400 => ProviderResult::failed(
                str_contains(strtolower($message), 'registration token') ? NotificationErrorCategory::InvalidDestination : NotificationErrorCategory::Permanent,
                $code,
                str_contains(strtolower($message), 'registration token') ? 'The device token is not valid.' : 'The provider rejected the message.'),
            $status === 429 || $fcmCode === 'QUOTA_EXCEEDED' => ProviderResult::failed(NotificationErrorCategory::RateLimited, $code, 'The provider rate limit was reached.', max(60, $retry ?? 60)),
            $status === 401 || $status === 403 => ProviderResult::failed(NotificationErrorCategory::AuthConfiguration, $code, 'The provider rejected the server credentials.'),
            $status === 503 || $status === 500 || $fcmCode === 'UNAVAILABLE' || $fcmCode === 'INTERNAL' => ProviderResult::failed(NotificationErrorCategory::Transient, $code, 'The provider is temporarily unavailable.', $retry),
            default => ProviderResult::failed(NotificationErrorCategory::Unknown, $code, 'The provider answered with an unexpected error.'),
        };
    }

    private function projectId(): ?string
    {
        $configured = (string) config('notifications.fcm.project_id');
        if ($configured !== '') {
            return $configured;
        }
        $json = $this->credentialsJson();

        return is_array($json) && isset($json['project_id']) ? (string) $json['project_id'] : null;
    }

    private function credentialsJson(): ?array
    {
        $path = (string) config('notifications.fcm.credentials');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $json = $this->credentialsJson();
        if ($json === null) {
            throw new \RuntimeException('Firebase credentials unreadable');
        }
        $credentials = new ServiceAccountCredentials((string) config('notifications.fcm.scope'), $json);
        $token = $credentials->fetchAuthToken();
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('No access token in the OAuth answer');
        }
        $ttl = max(60, (int) ($token['expires_in'] ?? 3600) - 120);
        Cache::put(self::TOKEN_CACHE_KEY, $access, $ttl);

        return $access;
    }

    private function client(): ClientInterface
    {
        return $this->http ??= new Client;
    }
}
