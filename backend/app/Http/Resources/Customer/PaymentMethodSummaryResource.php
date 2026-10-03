<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved payment method as the customer sees it (Module 25): the FoodOnTheGo id and the safe display
 * metadata the provider allows. Never the provider references, never a card number, CVV or UPI PIN — none of
 * those exist in this application.
 *
 * @mixin CustomerPaymentMethod
 */
class PaymentMethodSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerPaymentMethod $method */
        $method = $this->resource;

        return [
            'id' => $method->public_id,
            'type' => $method->type->value,
            'provider' => $method->provider,
            'brand' => $method->brand,
            'display_label' => $method->display_label,
            'last4' => $method->last4,
            'expiry' => $method->expiry_month === null || $method->expiry_year === null ? null : ['month' => (int) $method->expiry_month, 'year' => (int) $method->expiry_year],
            'upi_handle_masked' => $method->upi_handle_masked,
            'is_default' => (bool) $method->is_default,
            'status' => $method->effectiveStatus()->value,
            'created_at' => $method->created_at?->toIso8601String(),
        ];
    }
}
