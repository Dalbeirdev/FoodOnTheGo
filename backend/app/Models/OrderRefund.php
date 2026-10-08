<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that has to go back for an order (foundation, Module 31). A rejected paid order writes one REQUIRED row
 * for its full amount; nothing here calls the provider, and nothing here ever says "refunded" — the refund module
 * will move it through PENDING to PROCESSED on the provider's own word.
 */
class OrderRefund extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const STATUS_REQUIRED = 'REQUIRED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSED = 'PROCESSED';

    public const STATUS_FAILED = 'FAILED';

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'requested_at' => 'datetime'];
    }
}
