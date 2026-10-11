<?php

namespace App\Services\Notifications\Providers;

use App\Contracts\Notifications\PushNotificationProvider;
use App\Enums\NotificationErrorCategory;
use Illuminate\Support\Facades\Cache;

/**
 * The deterministic development provider: no message leaves the machine. The outcome is read from the token itself
 * (a token containing "unregistered" is an unregistered device, "transient" a 503, "quota" a 429, "authfail" a
 * credential failure); everything else is accepted. The last messages are kept in the cache WITHOUT the token so a
 * local run can inspect exactly what a real provider would have received.
 */
final class FakePushProvider implements PushNotificationProvider
{
    public const CACHE_KEY = 'notifications:fake-push:last';

    public function name(): string
    {
        return 'fake';
    }

    public function send(PushMessage $message): ProviderResult
    {
        $markers = config('notifications.fake');
        $token = $message->token;
        $this->remember($message);

        if (str_contains($token, (string) $markers['unregistered_marker'])) {
            return ProviderResult::failed(NotificationErrorCategory::InvalidDestination, 'UNREGISTERED', 'The device token is no longer registered (simulated).');
        }
        if (str_contains($token, (string) $markers['transient_marker'])) {
            return ProviderResult::failed(NotificationErrorCategory::Transient, 'UNAVAILABLE', 'The provider is temporarily unavailable (simulated).', 1);
        }
        if (str_contains($token, (string) $markers['quota_marker'])) {
            return ProviderResult::failed(NotificationErrorCategory::RateLimited, 'QUOTA_EXCEEDED', 'The sending rate is too high (simulated).', 60);
        }
        if (str_contains($token, (string) $markers['auth_marker'])) {
            return ProviderResult::failed(NotificationErrorCategory::AuthConfiguration, 'UNAUTHENTICATED', 'The provider rejected the server credentials (simulated).');
        }

        return ProviderResult::sent('fake-'.substr(hash('sha256', $message->deliveryPublicId), 0, 16));
    }

    public function health(): array
    {
        return ['status' => 'OPERATIONAL', 'driver' => 'fake', 'detail' => 'Development provider — no real push is sent; outcomes are simulated from the token. REAL FCM = PENDING FIREBASE CONFIGURATION.', 'latency_ms' => 0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function lastMessages(): array
    {
        return array_values((array) Cache::get(self::CACHE_KEY, []));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function remember(PushMessage $message): void
    {
        $keep = max(1, (int) config('notifications.fake.keep_last'));
        $list = (array) Cache::get(self::CACHE_KEY, []);
        $list[] = ['delivery' => $message->deliveryPublicId, 'at' => now()->toIso8601String(), 'message' => $message->toFcm(false), 'bytes' => $message->payloadBytes()];
        Cache::put(self::CACHE_KEY, array_slice($list, -$keep), 86400);
    }
}
