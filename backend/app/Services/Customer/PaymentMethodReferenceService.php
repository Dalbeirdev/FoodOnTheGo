<?php

namespace App\Services\Customer;

use App\Auth\Principal;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Provider-managed payment methods of a customer (Module 25) — references and safe display metadata only.
 *
 *  - FoodOnTheGo never accepts or stores a card number, CVV, UPI PIN or bank credential; there is no endpoint
 *    that takes one. A method is attached by the payment provider's secure flow (Module 30), which hands the
 *    backend a provider reference; today only development fixtures create rows (provider "development").
 *  - The provider references are encrypted at rest and never serialised; clients see the FoodOnTheGo public id.
 *  - Removing marks the row REVOKED (the payment history of later modules keeps referring to it); the provider
 *    detach itself is Module 30 — a removal today is a local reference change only.
 *  - One default per customer, guaranteed by the database.
 */
final class PaymentMethodReferenceService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Methods the customer can see (revoked ones are not), default first. A card past its expiry month is
     * marked EXPIRED as it is read.
     *
     * @return Collection<int, CustomerPaymentMethod>
     */
    public function list(Customer $customer): Collection
    {
        $methods = CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->where('status', '!=', PaymentMethodStatus::Revoked->value)
            ->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get();
        foreach ($methods as $method) {
            /** @var CustomerPaymentMethod $method */
            if ($method->status === PaymentMethodStatus::Active && $method->effectiveStatus() === PaymentMethodStatus::Expired) {
                $method->forceFill(['status' => PaymentMethodStatus::Expired, 'is_default' => false])->save();
            }
        }

        return $methods;
    }

    public function find(Customer $customer, string $publicId): CustomerPaymentMethod
    {
        return CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->where('public_id', $publicId)->first()
            ?? throw ApiException::notFound('payment_method_not_found', 'This payment method does not exist.');
    }

    public function makeDefault(Customer $customer, CustomerPaymentMethod $method, Principal $actor): CustomerPaymentMethod
    {
        return DB::transaction(function () use ($customer, $method, $actor): CustomerPaymentMethod {
            CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->lockForUpdate()->get();
            $locked = CustomerPaymentMethod::query()->whereKey($method->getKey())->firstOrFail();
            if (! $locked->effectiveStatus()->isUsable()) {
                throw new ApiException(422, 'payment_method_not_usable', 'This payment method cannot be used any more.');
            }
            CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->where('is_default', true)->update(['is_default' => false]);
            $locked->forceFill(['is_default' => true])->save();
            $this->audit->record('customer.payment_method_default', $locked, $actor, ['default' => ['from' => null, 'to' => $locked->public_id]], null, $customer->market_id === null ? null : (int) $customer->market_id);

            return $locked->refresh();
        });
    }

    /**
     * Local reference change only until Module 30 detaches the method at the provider as well.
     */
    public function revoke(Customer $customer, CustomerPaymentMethod $method, Principal $actor): void
    {
        DB::transaction(function () use ($customer, $method, $actor): void {
            CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->lockForUpdate()->get();
            $locked = CustomerPaymentMethod::query()->whereKey($method->getKey())->firstOrFail();
            if ($locked->status === PaymentMethodStatus::Revoked) {
                return;
            }
            $wasDefault = $locked->is_default;
            $locked->forceFill(['status' => PaymentMethodStatus::Revoked, 'is_default' => false, 'revoked_at' => now()])->save();
            if ($wasDefault) {
                $next = CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->where('status', PaymentMethodStatus::Active->value)->orderBy('created_at')->orderBy('id')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
            $this->audit->record('customer.payment_method_revoked', $locked, $actor, ['status' => ['from' => 'ACTIVE', 'to' => 'REVOKED']], null, $customer->market_id === null ? null : (int) $customer->market_id);
        });
    }

    /**
     * Attaches a provider reference the backend received from a provider flow (Module 30) or a development
     * fixture. Never called with credentials: the caller has only what the provider returned.
     *
     * @param  array{type: string, provider: string, provider_customer_reference?: string|null, provider_payment_method_reference: string, brand?: string|null, display_label: string, last4?: string|null, expiry_month?: int|null, expiry_year?: int|null, upi_handle_masked?: string|null, is_default?: bool}  $reference
     */
    public function attach(Customer $customer, array $reference): CustomerPaymentMethod
    {
        return DB::transaction(function () use ($customer, $reference): CustomerPaymentMethod {
            $hash = hash('sha256', $reference['provider'].':'.$reference['provider_payment_method_reference']);
            $existing = CustomerPaymentMethod::query()->where('provider', $reference['provider'])->where('reference_hash', $hash)->first();
            if ($existing !== null) {
                return $existing;
            }
            $makeDefault = (bool) ($reference['is_default'] ?? false) || ! CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->where('is_default', true)->exists();
            if ($makeDefault) {
                CustomerPaymentMethod::query()->where('customer_id', $customer->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
            $method = (new CustomerPaymentMethod)->forceFill([
                'customer_id' => $customer->getKey(), 'provider' => $reference['provider'],
                'provider_customer_reference' => $reference['provider_customer_reference'] ?? null, 'provider_payment_method_reference' => $reference['provider_payment_method_reference'], 'reference_hash' => $hash,
                'type' => PaymentMethodType::from($reference['type']), 'brand' => $reference['brand'] ?? null, 'display_label' => $reference['display_label'],
                'last4' => $reference['last4'] ?? null, 'expiry_month' => $reference['expiry_month'] ?? null, 'expiry_year' => $reference['expiry_year'] ?? null,
                'upi_handle_masked' => $reference['upi_handle_masked'] ?? null, 'is_default' => $makeDefault, 'status' => PaymentMethodStatus::Active,
            ]);
            $method->save();

            return $method->refresh();
        });
    }
}
