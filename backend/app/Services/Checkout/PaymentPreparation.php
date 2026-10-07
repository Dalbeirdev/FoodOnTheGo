<?php

namespace App\Services\Checkout;

use App\Enums\CheckoutStatus;
use App\Models\CheckoutSession;
use App\Services\Payment\PaymentProviderManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * What Module 30 needs to start a payment for a confirmed checkout — prepared, not executed (Module 29).
 *
 * The amount and the currency are the checkout's own and come from nowhere else: no request field can lower them.
 * The preparation is bound to one exact price (the pricing fingerprint) and one checkout version; its idempotency
 * key is derived from them, so the same checkout can only ever lead to one payment for that price. When the total is
 * zero no provider is involved at all (PAYMENT_NOT_REQUIRED).
 *
 * Nothing here talks to a payment provider, and nothing here holds card data, a UPI PIN or a bank credential.
 */
final readonly class PaymentPreparation
{
    public const READY = 'READY_FOR_PAYMENT';

    public const NOT_REQUIRED = 'PAYMENT_NOT_REQUIRED';

    private function __construct(
        public string $status,
        public string $checkoutId,
        public int $checkoutVersion,
        public Money $amount,
        public string $pricingFingerprint,
        public string $idempotencyKey,
        public ?string $paymentMethodType,
        public ?string $savedPaymentMethodId,
        public CarbonImmutable $expiresAt,
        /** @var array{status: string, name: string|null, mode: string|null} */
        public array $provider,
    ) {}

    /**
     * @throws LogicException when the checkout has not been confirmed
     */
    public static function for(CheckoutSession $session): self
    {
        if (! in_array($session->status, [CheckoutStatus::Ready, CheckoutStatus::PaymentPending], true)) {
            throw new LogicException('A payment can only be prepared for a confirmed checkout.');
        }

        return new self(
            status: $session->payment_required ? self::READY : self::NOT_REQUIRED,
            checkoutId: (string) $session->public_id,
            checkoutVersion: (int) $session->version,
            amount: $session->total(),
            pricingFingerprint: (string) $session->pricing_fingerprint,
            idempotencyKey: 'pay_'.substr(hash('sha256', $session->public_id.'|'.$session->pricing_fingerprint.'|'.$session->version), 0, 40),
            paymentMethodType: $session->payment_method_type,
            savedPaymentMethodId: $session->savedPaymentMethod?->public_id,
            expiresAt: CarbonImmutable::instance($session->expires_at),
            provider: app(PaymentProviderManager::class)->summary(),
        );
    }

    /**
     * The customer-safe form: amounts, references and states — no provider reference, no internal id.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checkout_id' => $this->checkoutId,
            'checkout_version' => $this->checkoutVersion,
            'amount' => $this->amount->toArray(),
            'currency' => $this->amount->currency,
            'pricing_fingerprint' => $this->pricingFingerprint,
            'idempotency_key' => $this->idempotencyKey,
            'payment_method' => $this->paymentMethodType === null ? null : ['type' => $this->paymentMethodType, 'saved_method_id' => $this->savedPaymentMethodId],
            'expires_at' => $this->expiresAt->utc()->toIso8601String(),
            'provider' => $this->provider,
        ];
    }
}
