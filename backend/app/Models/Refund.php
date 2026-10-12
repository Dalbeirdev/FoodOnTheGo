<?php

namespace App\Models;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money that goes back to a customer (Module 35) — a financial record of its own.
 *
 * It belongs to one verified payment attempt and, when that payment became an order, to the order. It never
 * rewrites either of them. Its state changes only through RefundStateMachine, its amount is decided on the server,
 * and it is never deleted.
 */
class Refund extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const TYPE_FULL = 'FULL';

    public const TYPE_PARTIAL = 'PARTIAL';

    public const BY_SYSTEM = 'SYSTEM';

    public const BY_CUSTOMER = 'CUSTOMER';

    public const BY_RESTAURANT = 'RESTAURANT_USER';

    public const BY_ADMIN = 'ADMIN';

    public const APPROVAL_AUTO = 'AUTO';

    public const APPROVAL_MANUAL = 'MANUAL';

    protected $guarded = ['*'];

    /** The amount that is (or would be) refunded: what was approved, and before that what was asked for. */
    public function amountMinor(): int
    {
        return (int) ($this->approved_amount_minor ?? $this->requested_amount_minor);
    }

    public function amount(): Money
    {
        return new Money($this->amountMinor(), (string) $this->currency);
    }

    /**
     * A short reference a customer can read to support. Derived from the public id; it opens nothing.
     */
    public function supportReference(): string
    {
        return 'RFD-'.strtoupper(substr(str_replace('-', '', (string) $this->public_id), 0, 10));
    }

    /**
     * Our reference of the provider refund of one generation: the receipt and the idempotency key in one.
     * Letters, digits and hyphens, 36–38 characters (the provider allows 40).
     */
    public function receiptFor(int $generation): string
    {
        return 'rf-'.str_replace('-', '', (string) $this->public_id).'-'.$generation;
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'closed_by_admin_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RefundAttempt::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RefundEvent::class)->orderBy('id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'reason_code' => RefundReason::class,
            'requested_amount_minor' => 'integer',
            'approved_amount_minor' => 'integer',
            'version' => 'integer',
            'retryable' => 'boolean',
            'generation' => 'integer',
            'submit_attempts' => 'integer',
            'unknown_resubmits' => 'integer',
            'reconcile_runs' => 'integer',
            'next_action_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'approved_at' => 'datetime',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
