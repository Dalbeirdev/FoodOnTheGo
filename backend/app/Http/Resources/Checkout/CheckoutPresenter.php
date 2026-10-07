<?php

namespace App\Http\Resources\Checkout;

use App\Enums\CheckoutStatus;
use App\Http\Resources\Customer\PaymentMethodSummaryResource;
use App\Models\CheckoutLegalAcceptance;
use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Services\Checkout\CheckoutView;
use App\Services\Checkout\LegalDocuments;
use App\Services\Checkout\PaymentOptions;
use App\Services\Checkout\PaymentPreparation;
use App\Services\Checkout\PickupSlotHoldService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A checkout as its owner sees it (Module 29): what they are about to pay for, priced by the backend.
 *
 *  - every amount is a Money object ({amount in minor units, currency}) calculated on the server; the cart shows a
 *    merchandise subtotal, a checkout shows the complete payable breakdown;
 *  - a tax or fee line exists only when a configured rule produced it — no placeholder lines, no delivery fee;
 *  - ids are public ids; no internal id, no rule internals beyond the label and rate a customer may see, no provider
 *    data;
 *  - `payment` is a PREPARATION for Module 30 (amount, currency, the price it is bound to) — nothing has been charged;
 *  - this is not a receipt and not a tax invoice.
 */
final class CheckoutPresenter
{
    public function __construct(
        private readonly LegalDocuments $legal,
        private readonly PaymentOptions $payments,
        private readonly PickupSlotHoldService $holds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(CheckoutView $view, Customer $customer): array
    {
        $session = $view->session->loadMissing(['market', 'legalAcceptances', 'savedPaymentMethod']);
        $snapshot = (array) $session->snapshot;
        $currency = (string) $session->currency;
        $timezone = (string) $session->restaurant_timezone;
        $money = fn (int $amount): array => ['amount' => $amount, 'currency' => $currency];
        $at = CarbonImmutable::now();
        $required = $this->legal->requiredFor($session->market);
        $hold = $session->status === CheckoutStatus::Ready || $session->status === CheckoutStatus::PaymentPending ? $this->holds->active($session, $at) : null;
        $pickupAt = CarbonImmutable::instance($session->pickup_at);
        $promotion = $snapshot['promotion'] ?? null;
        $saved = $this->payments->saved($customer);

        return [
            'id' => (string) $session->public_id,
            'status' => $session->status->value,
            'status_reason' => $session->status_reason,
            'version' => (int) $session->version,
            'expires_at' => self::utc($session->expires_at),
            'server_time' => self::utc($at),
            'created_at' => self::utc($session->created_at),
            'cart_version' => (int) $session->cart_version,
            'restaurant' => $snapshot['restaurant'] ?? null,
            'pickup' => [
                'mode' => $session->pickup_mode->value,
                'method' => $session->pickup_method->value,
                'pickup_at' => self::utc($pickupAt),
                'local_pickup_at' => $pickupAt->setTimezone($timezone)->toIso8601String(),
                'local_date' => $pickupAt->setTimezone($timezone)->format('Y-m-d'),
                'slot_starts_at' => self::utc($session->pickup_slot_starts_at),
                'slot_ends_at' => self::utc($session->pickup_slot_ends_at),
                'timezone' => $timezone,
                'estimated_prep_minutes' => $snapshot['pickup']['estimated_prep_minutes'] ?? null,
                'traveler_arrival_at' => $snapshot['pickup']['traveler_arrival_at'] ?? null,
                // Held only once the checkout is confirmed, and only until it expires.
                'hold' => ['held' => $hold !== null, 'expires_at' => self::utc($hold?->expires_at)],
            ],
            'items' => $snapshot['items'] ?? [],
            'item_count' => (int) ($snapshot['item_count'] ?? 0),
            'pricing' => [
                'currency' => $currency,
                'items_subtotal' => $money((int) $session->subtotal_minor),
                'discounts' => $promotion === null ? [] : [['code' => (string) $promotion['code'], 'label' => (string) $promotion['name'], 'amount' => $money((int) $session->discount_minor)]],
                'discount_total' => $money((int) $session->discount_minor),
                'taxes' => array_map(fn (array $tax): array => [
                    'code' => $tax['code'],
                    'label' => $tax['label'],
                    'applies_to' => $tax['applies_to'],
                    'rate_basis_points' => $tax['rate_basis_points'],
                    'taxable_amount' => $money((int) $tax['taxable_amount_minor']),
                    'amount' => $money((int) $tax['amount_minor']),
                    'included_in_prices' => (bool) $tax['included_in_prices'],
                ], (array) ($snapshot['taxes'] ?? [])),
                // Added on top of the prices, and — separately — what the prices already contain.
                'tax_total' => $money((int) $session->tax_minor),
                'tax_included_total' => $money((int) $session->tax_included_minor),
                'fees' => array_map(fn (array $fee): array => ['code' => $fee['code'], 'label' => $fee['label'], 'amount' => $money((int) $fee['amount_minor']), 'taxable' => (bool) $fee['taxable']], (array) ($snapshot['fees'] ?? [])),
                'fee_total' => $money((int) $session->fee_minor),
                'total' => $money((int) $session->total_minor),
                'payment_required' => (bool) $session->payment_required,
                'fingerprint' => (string) $session->pricing_fingerprint,
                // NOT_CONFIGURED = no rule exists (nothing was invented); TEST = a development configuration.
                'configuration' => $snapshot['configuration'] ?? ['tax' => 'NOT_CONFIGURED', 'fees' => 'NOT_CONFIGURED'],
            ],
            'promotion' => $promotion === null ? null : ['code' => (string) $promotion['code'], 'name' => (string) $promotion['name'], 'discount' => $money((int) $session->discount_minor)],
            // The account's own verified details — the phone number is identity and cannot be changed here.
            'customer' => ['name' => $customer->name, 'phone' => (string) $customer->phone_e164, 'phone_verified' => $customer->phone_verified_at !== null, 'email' => $customer->email],
            'order_note' => $session->order_note,
            'legal' => [
                'required' => $required,
                'accepted' => $session->legalAcceptances->map(fn (CheckoutLegalAcceptance $a): array => ['type' => $a->document_type->value, 'version' => (string) $a->document_version, 'status' => (string) $a->document_status, 'accepted_at' => self::utc($a->accepted_at)])->values()->all(),
                // False while any text is a draft: recorded honestly, a production blocker.
                'final_approval' => $this->legal->finalApproval($required),
            ],
            'payment_methods' => [
                'provider_status' => (string) config('checkout.payment.provider_status'),
                'available' => $this->payments->methods($session->market),
                'saved' => $saved->map(fn ($m): array => (new PaymentMethodSummaryResource($m))->toArray(new Request))->values()->all(),
                'selected' => $session->payment_method_type === null ? null : ['type' => (string) $session->payment_method_type, 'saved_method_id' => $session->savedPaymentMethod?->public_id],
                'cash_at_pickup' => 'NOT_APPROVED',
            ],
            'payment' => $this->payment($session),
            'issues' => array_map(fn (array $issue): array => [...$issue, 'details' => (object) $issue['details']], $view->issues),
            'changes' => $view->created ? $view->changes : (array) ($snapshot['changes'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payment(CheckoutSession $session): ?array
    {
        return in_array($session->status, [CheckoutStatus::Ready, CheckoutStatus::PaymentPending], true) ? PaymentPreparation::for($session)->toArray() : null;
    }

    private static function utc(mixed $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->utc()->toIso8601String();
    }
}
