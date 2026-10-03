<?php

namespace App\Models;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider-managed payment method of a customer (Module 25): the provider's references (encrypted at rest,
 * never serialised) plus the safe display metadata the provider allows (brand, last four digits, expiry,
 * masked UPI handle). FoodOnTheGo never holds a card number, CVV, UPI PIN or bank credential — there is no
 * endpoint that accepts one. Attaching a method is the provider's secure flow (Module 30).
 */
#[Hidden(['provider_customer_reference', 'provider_payment_method_reference', 'reference_hash'])]
class CustomerPaymentMethod extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The status as it should be shown: a card whose expiry month has passed is EXPIRED even if the stored
     * status still says ACTIVE (the row is updated by the next list).
     */
    public function effectiveStatus(): PaymentMethodStatus
    {
        if ($this->status === PaymentMethodStatus::Active && $this->expiry_year !== null && $this->expiry_month !== null) {
            $lastDay = CarbonImmutable::create((int) $this->expiry_year, (int) $this->expiry_month, 1, 0, 0, 0, 'UTC')->endOfMonth();
            if ($lastDay->isPast()) {
                return PaymentMethodStatus::Expired;
            }
        }

        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class, 'status' => PaymentMethodStatus::class, 'is_default' => 'boolean',
            'provider_customer_reference' => 'encrypted', 'provider_payment_method_reference' => 'encrypted',
            'expiry_month' => 'integer', 'expiry_year' => 'integer', 'revoked_at' => 'datetime',
        ];
    }
}
