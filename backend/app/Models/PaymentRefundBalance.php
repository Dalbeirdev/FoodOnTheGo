<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one payment can still give back (Module 35). One row per payment that has, or had, a refund.
 *
 * The row is the lock of every refund change of its payment, and its CHECK (reserved + refunded <= paid) is the
 * last word: whatever the code is asked, the database does not take an over-refund.
 */
class PaymentRefundBalance extends Model
{
    use StoresUtcTimestamps;

    protected $primaryKey = 'payment_attempt_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    /** What a new refund may still ask for: paid, less what is held, confirmed, or taken by a lost dispute. */
    public function availableMinor(): int
    {
        return max(0, (int) $this->paid_minor - (int) $this->reserved_minor - (int) $this->refunded_minor - (int) $this->charged_back_minor);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    protected function casts(): array
    {
        return ['paid_minor' => 'integer', 'reserved_minor' => 'integer', 'refunded_minor' => 'integer', 'charged_back_minor' => 'integer', 'version' => 'integer'];
    }
}
