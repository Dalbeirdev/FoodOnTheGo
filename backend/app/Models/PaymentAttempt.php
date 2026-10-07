<?php

namespace App\Models;

use App\Enums\PaymentFailureCategory;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One try to pay one confirmed checkout through one provider order (Module 30).
 *
 * A financial record: the amount and currency are fixed when it is created, its state changes only through
 * PaymentStateMachine, and it is never deleted with the checkout or the cart it was made for.
 */
class PaymentAttempt extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function amount(): Money
    {
        return new Money((int) $this->amount_minor, (string) $this->currency);
    }

    /**
     * A short reference a customer can read to support. Derived from the public id; it opens nothing.
     */
    public function supportReference(): string
    {
        return 'PAY-'.strtoupper(substr(str_replace('-', '', (string) $this->public_id), 0, 10));
    }

    public function expired(?\DateTimeInterface $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentAttemptEvent::class)->orderBy('id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'fulfillment_status' => PaymentFulfillmentStatus::class,
            'failure_category' => PaymentFailureCategory::class,
            'checkout_version' => 'integer',
            'attempt_number' => 'integer',
            'amount_minor' => 'integer',
            'method_summary' => 'array',
            'signature_failures' => 'integer',
            'reconcile_runs' => 'integer',
            'client_reported_at' => 'datetime',
            'signature_verified_at' => 'datetime',
            'fulfillment_changed_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'next_reconcile_at' => 'datetime',
            'expires_at' => 'datetime',
            'provider_ready_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
