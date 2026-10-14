<?php

namespace App\Models;

use App\Enums\PhoneChangeStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to move a customer account to a new phone number (Module 25). The new number is proven by a
 * one-time code sent to it (Module 21 OTP, purpose customer_phone_change); the request expires with the code.
 */
class CustomerPhoneChange extends Model
{
    use HasPublicId, Prunable, StoresUtcTimestamps;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A phone-change request holds the new number: it is kept 30 days after it expired (support questions), then removed. Removed by `php artisan model:prune` (scheduled daily, Module 39).
     *
     * @return Builder<CustomerPhoneChange>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => PhoneChangeStatus::class, 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
