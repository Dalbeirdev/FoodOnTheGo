<?php

namespace App\Services\Payment;

use App\Enums\ProviderPaymentState;
use App\Services\Payment\Data\ProviderPayment;
use App\Support\Money;

/**
 * What is kept of the payment a webhook named — and how it is read back when the event is processed. The same
 * safe fields as everywhere: references, state, amount, method type, error codes. Not the payload.
 */
final class PaymentWebhookSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ProviderPayment $payment): array
    {
        return [
            'reference' => $payment->reference,
            'order' => $payment->orderReference,
            'state' => $payment->state->value,
            'provider_status' => $payment->providerStatus,
            'amount_minor' => $payment->amount->amount,
            'currency' => $payment->amount->currency,
            'method' => $payment->methodType,
            'method_summary' => $payment->methodSummary,
            'error_code' => $payment->errorCode,
            'error_reason' => $payment->errorReason,
            'error_source' => $payment->errorSource,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function payment(array $summary): ProviderPayment
    {
        return new ProviderPayment(
            reference: (string) ($summary['reference'] ?? ''),
            orderReference: is_string($summary['order'] ?? null) ? $summary['order'] : null,
            state: ProviderPaymentState::tryFrom((string) ($summary['state'] ?? '')) ?? ProviderPaymentState::Unknown,
            providerStatus: (string) ($summary['provider_status'] ?? ''),
            amount: new Money((int) ($summary['amount_minor'] ?? 0), (string) ($summary['currency'] ?? 'XXX')),
            methodType: is_string($summary['method'] ?? null) ? $summary['method'] : null,
            methodSummary: is_array($summary['method_summary'] ?? null) ? $summary['method_summary'] : [],
            errorCode: is_string($summary['error_code'] ?? null) ? $summary['error_code'] : null,
            errorReason: is_string($summary['error_reason'] ?? null) ? $summary['error_reason'] : null,
            errorSource: is_string($summary['error_source'] ?? null) ? $summary['error_source'] : null,
        );
    }
}
