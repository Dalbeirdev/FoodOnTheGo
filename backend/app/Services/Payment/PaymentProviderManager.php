<?php

namespace App\Services\Payment;

use App\Contracts\Payment\PaymentProvider;
use App\Exceptions\ProviderException;
use App\Services\Journey\ProviderMetrics;
use App\Services\Payment\Providers\FakePaymentProvider;
use App\Services\Payment\Providers\RazorpayPaymentProvider;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Builds the configured payment provider and is the only place that decides whether one may be used.
 *
 *  - Nothing configured: payments are unavailable. There is no default and no fallback.
 *  - "fake": refused wherever fake providers are not allowed — always in production.
 *  - "razorpay": needs its key id, key secret and webhook secret, and a test-mode key (live payments are not enabled).
 *
 * Every call to a provider goes through call(): it is timed, counted for the provider's health, and repeated only
 * when the caller says the operation is safe to repeat.
 */
final class PaymentProviderManager
{
    public const DRIVERS = [FakePaymentProvider::NAME, RazorpayPaymentProvider::NAME];

    public function __construct(private readonly ProviderMetrics $metrics) {}

    public function driver(): ?string
    {
        $driver = strtolower(trim((string) config('services.payment.driver')));

        return $driver === '' ? null : $driver;
    }

    /**
     * The provider new payments are created with.
     *
     * @throws ProviderException Misconfigured when payments cannot be taken here
     */
    public function provider(): PaymentProvider
    {
        $driver = $this->driver();
        if ($driver === null) {
            throw ProviderException::misconfigured('payment', 'no payment provider is configured');
        }

        return $this->usable($driver) ?? throw ProviderException::misconfigured($driver, 'the payment provider cannot be used here');
    }

    /**
     * The provider an existing attempt or an incoming webhook belongs to — only when that provider is the one
     * configured here and is usable. A webhook for another provider is not accepted by guessing.
     */
    public function named(string $name): ?PaymentProvider
    {
        $name = strtolower($name);

        return $name === $this->driver() ? $this->usable($name) : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws ProviderException
     */
    public function call(PaymentProvider $provider, string $operation, Closure $call, bool $safeToRepeat = false): mixed
    {
        $attempts = $safeToRepeat ? max(1, (int) config('payments.providers.retry.attempts')) : 1;
        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);
            try {
                $result = $call();
                $this->metrics->record($provider->name(), 'payment.'.$operation, 'ok', self::elapsedMs($started));

                return $result;
            } catch (ProviderException $e) {
                $this->metrics->record($provider->name(), 'payment.'.$operation, $e->failure->metric(), self::elapsedMs($started));
                $again = $e->failure->transient() && $attempt < $attempts;
                Log::info('payment.provider_failed', [
                    'request_id' => Context::get('request_id'), 'provider' => $provider->name(), 'operation' => $operation,
                    'failure' => $e->failure->value, 'attempt' => $attempt, 'will_retry' => $again,
                ]);
                if (! $again) {
                    throw $e;
                }
                usleep((max(0, (int) config('payments.providers.retry.backoff_ms')) * (2 ** ($attempt - 1)) + random_int(0, max(0, (int) config('payments.providers.retry.jitter_ms')))) * 1000);
            }
        }
    }

    /**
     * Whether a payment can be started here, in the terms a checkout answer uses: READY with the provider's name and
     * mode, or UNAVAILABLE. Cheap — no provider is called and no counter is read.
     *
     * @return array{status: 'READY'|'UNAVAILABLE', name: string|null, mode: string|null}
     */
    public function summary(): array
    {
        $driver = $this->driver();
        $provider = $driver === null ? null : $this->usable($driver);

        return ['status' => $provider === null ? 'UNAVAILABLE' : 'READY', 'name' => $provider?->name(), 'mode' => $provider?->mode()];
    }

    /**
     * What an administrator may know about the provider: names and states, never a value.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $driver = $this->driver();
        $provider = $driver !== null && in_array($driver, self::DRIVERS, true) ? $this->build($driver) : null;
        $missing = match (true) {
            $driver === null => ['PAYMENT_DRIVER'],
            $provider === null => ['PAYMENT_DRIVER (unknown provider)'],
            $provider->isFake() && ! (bool) config('payments.providers.allow_fake') => ['PAYMENT_DRIVER (the fake provider is not allowed here)'],
            default => $provider->missingConfiguration(),
        };

        return [
            'driver' => $driver,
            'mode' => $provider?->mode(),
            'configured' => $missing === [],
            'missing' => $missing,
            'fake' => (bool) $provider?->isFake(),
            'fake_allowed' => (bool) config('payments.providers.allow_fake'),
            'live_allowed' => (bool) config('payments.providers.allow_live'),
            'timeout_seconds' => (int) config('services.payment.timeout'),
            'methods' => $missing === [] ? $provider?->configuredMethods() : null,
            'health' => $driver === null ? null : $this->metrics->health('payment', $driver),
        ];
    }

    private function usable(string $driver): ?PaymentProvider
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            return null;
        }
        $provider = $this->build($driver);
        if ($provider->isFake() && ! (bool) config('payments.providers.allow_fake')) {
            return null;
        }

        return $provider->missingConfiguration() === [] ? $provider : null;
    }

    private function build(string $driver): PaymentProvider
    {
        // Built on demand from the configuration as it is now: cheap, and never out of date.
        return match ($driver) {
            FakePaymentProvider::NAME => new FakePaymentProvider,
            RazorpayPaymentProvider::NAME => new RazorpayPaymentProvider((array) config('services.razorpay') + [
                'timeout' => (int) config('services.payment.timeout'),
                'allow_live' => (bool) config('payments.providers.allow_live'),
            ]),
        };
    }

    private static function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
