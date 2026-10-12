<?php

namespace App\Services\Finance\ProviderSettlement;

use App\Contracts\Finance\ProviderSettlementSource;
use App\Exceptions\ProviderException;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\Providers\FakePaymentProvider;

/**
 * Which provider settlement records can be read here (Module 36): those of the payment provider that is configured —
 * and only when that provider is usable, which the payment provider manager decides (the fake one never in
 * production; Razorpay in test mode only, and only with credentials — none exist on this machine: PENDING PROVIDER
 * VERIFICATION). No credential is read here: the provider's adapter holds them and nothing else does.
 */
final class ProviderSettlementSources
{
    public function __construct(private readonly PaymentProviderManager $payments) {}

    public function current(): ?ProviderSettlementSource
    {
        try {
            $provider = $this->payments->provider();
        } catch (ProviderException) {
            return null;
        }
        if ($provider->name() === FakePaymentProvider::NAME) {
            return new FakeSettlementSource;
        }

        return $provider instanceof ProviderSettlementSource ? $provider : null;
    }

    /**
     * What an administrator may know: names and states, never a value.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $driver = $this->payments->driver();
        $source = $this->current();

        return [
            'payment_driver' => $driver,
            'status' => match (true) {
                $driver === null => 'NOT_CONFIGURED',
                $source === null => 'UNAVAILABLE',
                $source instanceof FakeSettlementSource => 'FAKE_PROVIDER',
                default => 'CONFIGURED',
            },
            'mode' => $source === null ? null : strtolower($source->mode()),
            // The Razorpay reader exists and was never run against Razorpay.
            'razorpay_settlement_api' => 'PENDING PROVIDER VERIFICATION',
            'scheduled' => (bool) config('finance.provider_reconciliation.scheduled'),
        ];
    }
}
