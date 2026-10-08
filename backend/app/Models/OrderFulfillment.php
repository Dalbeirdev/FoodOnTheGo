<?php

namespace App\Models;

use App\Enums\OrderFulfillmentStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one record of turning a checkout into an order (Module 31). One row per checkout — the row every worker
 * locks before it tries, so that of two workers exactly one creates the order and the other finds it. It keeps
 * what happened when the order could not be made: how often it was tried, why it failed, when it is tried again.
 */
class OrderFulfillment extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const TRIGGER_PAYMENT_VERIFIED = 'PAYMENT_VERIFIED';

    public const TRIGGER_ZERO_TOTAL = 'ZERO_TOTAL';

    public const TRIGGER_RECOVERY = 'RECOVERY';

    protected $guarded = ['*'];

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['status' => OrderFulfillmentStatus::class, 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
