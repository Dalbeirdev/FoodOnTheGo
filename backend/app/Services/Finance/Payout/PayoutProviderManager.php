<?php

namespace App\Services\Finance\Payout;

use App\Contracts\Finance\PayoutProvider;
use App\Exceptions\ProviderException;
use App\Services\Journey\ProviderMetrics;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Builds the configured payout provider and is the only place that decides whether one may be used (Module 36).
 *
 *  - Nothing configured (PAYOUT_DRIVER empty): payouts through a provider are unavailable. No default, no fallback.
 *  - "fake": refused wherever fake providers are not allowed — always in production.
 *  - anything else: there is no such adapter. And if one is ever written, it stays refused while
 *    finance.payouts.live_enabled is false — which no environment variable can change.
 *
 * LIVE PAYOUT = DISABLED. A call to a payout provider is NEVER repeated here: a payout that got no answer is settled
 * by asking the provider what it has.
 */
final class PayoutProviderManager
{
    public const DRIVERS = [FakePayoutProvider::NAME];

    public function __construct(private readonly ProviderMetrics $metrics) {}

    public function driver(): ?string
    {
        $driver = strtolower(trim((string) config('finance.payouts.driver')));

        return $driver === '' ? null : $driver;
    }

    /**
     * @throws ProviderException Misconfigured when payouts cannot be sent here
     */
    public function provider(): PayoutProvider
    {
        $driver = $this->driver();
        if ($driver === null) {
            throw ProviderException::misconfigured('payout', 'no payout provider is configured');
        }

        return $this->usable($driver) ?? throw ProviderException::misconfigured($driver, 'the payout provider cannot be used here');
    }

    /**
     * The provider an existing payout or an incoming webhook belongs to — only when it is the one configured here.
     */
    public function named(string $name): ?PayoutProvider
    {
        $name = strtolower($name);

        return $name === $this->driver() ? $this->usable($name) : null;
    }

    /**
     * One call, timed and counted for the provider's health. Never repeated.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws ProviderException
     */
    public function call(PayoutProvider $provider, string $operation, Closure $call): mixed
    {
        $started = hrtime(true);
        try {
            $result = $call();
            $this->metrics->record($provider->name(), 'payout.'.$operation, 'ok', (int) ((hrtime(true) - $started) / 1_000_000));

            return $result;
        } catch (ProviderException $e) {
            $this->metrics->record($provider->name(), 'payout.'.$operation, $e->failure->metric(), (int) ((hrtime(true) - $started) / 1_000_000));
            Log::info('payout.provider_failed', ['request_id' => Context::get('request_id'), 'provider' => $provider->name(), 'operation' => $operation, 'failure' => $e->failure->value]);
            throw $e;
        }
    }

    /**
     * What an administrator may know: names and states, never a value. Missing production configuration stays
     * visible — a fake provider is never reported as a working payout provider.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $driver = $this->driver();
        $provider = $driver === null ? null : $this->usable($driver);
        $status = match (true) {
            $driver === null => 'NOT_CONFIGURED',
            $provider === null => 'UNAVAILABLE',
            $provider->isFake() => 'FAKE_PROVIDER',
            default => 'CONFIGURED',
        };

        return [
            'driver' => $driver,
            'status' => $status,
            'mode' => $provider?->mode(),
            'fake' => (bool) $provider?->isFake(),
            'fake_allowed' => (bool) config('payments.providers.allow_fake'),
            'live_enabled' => (bool) config('finance.payouts.live_enabled'),
            'live_payout' => 'DISABLED',
            'real_adapter' => 'NOT_IMPLEMENTED',
            'provider_account' => 'PENDING PROVIDER ACCOUNT ENABLEMENT',
            'restaurant_onboarding' => 'PENDING (no KYC / linked-account onboarding exists)',
            'health' => $provider === null ? null : $this->metrics->health('payout', (string) $driver),
        ];
    }

    private function usable(string $driver): ?PayoutProvider
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            return null;
        }
        $provider = new FakePayoutProvider;
        if ($provider->isFake() && (app()->isProduction() || ! (bool) config('payments.providers.allow_fake'))) {
            return null;
        }
        if (! $provider->isFake() && ! (bool) config('finance.payouts.live_enabled')) {
            return null;
        }

        return $provider->missingConfiguration() === [] ? $provider : null;
    }
}
