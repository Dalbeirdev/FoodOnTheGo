<?php

namespace App\Services\Checkout;

use App\Enums\PaymentMethodStatus;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Market;
use App\Models\MarketConfiguration;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which ways to pay a checkout may name (Module 29). Choosing one is CONTEXT for the payment that Module 30 will
 * start — it charges nothing and it is not a provider capability check: no provider is integrated yet, and the
 * answer says so.
 *
 * The method types come from the market's payment configuration; a customer's saved methods are the safe references
 * of Module 25 (a label and the last four digits — never a card number). Cash at pickup is NOT APPROVED: it is never
 * offered and never accepted, whatever a client sends.
 */
final class PaymentOptions
{
    /** States of a configured method in which it must not be offered. */
    private const NOT_OFFERED = ['NOT_APPROVED', 'DISABLED', 'BLOCKED'];

    /**
     * @return list<array{type: string, status: string}>
     */
    public function methods(Market $market): array
    {
        $configured = (array) (MarketConfiguration::query()->where('market_id', $market->getKey())->value('payment')['methods'] ?? []);
        $methods = [];
        foreach ((array) config('checkout.payment.methods') as $key => $type) {
            $status = strtoupper((string) ($configured[$key] ?? ''));
            if ($status !== '' && ! in_array($status, self::NOT_OFFERED, true)) {
                $methods[] = ['type' => (string) $type, 'status' => $status];
            }
        }

        return $methods;
    }

    public function offers(Market $market, string $type): bool
    {
        return in_array($type, array_column($this->methods($market), 'type'), true);
    }

    /**
     * The customer's own usable saved payment-method references.
     *
     * @return Collection<int, CustomerPaymentMethod>
     */
    public function saved(Customer $customer): Collection
    {
        return CustomerPaymentMethod::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', PaymentMethodStatus::Active->value)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            // A card past its expiry date is no longer usable, whatever its row says.
            ->filter(fn (CustomerPaymentMethod $method): bool => $method->effectiveStatus() === PaymentMethodStatus::Active)
            ->values();
    }
}
