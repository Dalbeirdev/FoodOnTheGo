<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dispute / chargeback a provider reported on a payment (Module 35 foundation). Written only from verified
 * provider events; people read it. It is not a refund and never becomes one.
 */
class PaymentDispute extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function amount(): Money
    {
        return new Money((int) $this->amount_minor, (string) $this->currency);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'amount_minor' => 'integer',
            'amount_deducted_minor' => 'integer',
            'version' => 'integer',
            'opened_at' => 'datetime',
            'respond_by_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }
}
