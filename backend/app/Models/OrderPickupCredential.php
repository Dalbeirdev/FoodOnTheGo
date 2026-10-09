<?php

namespace App\Models;

use App\Enums\PickupCredentialStatus;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What proves a pickup (Module 31 foundation, Module 32 verification): an opaque random secret behind the QR code
 * and a short code the customer can read out. Both are stored ENCRYPTED (the customer sees the same QR and code on
 * every visit and device) next to a hash that is the only thing a lookup or a comparison touches. Neither carries a
 * customer, an order number, a payment or an amount. One ACTIVE credential per order; a rotation revokes the old row
 * and issues a new one; a successful verification consumes it exactly once (consumed_at, by whom, by which method).
 */
class OrderPickupCredential extends Model
{
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(RestaurantUser::class, 'consumed_by_restaurant_user_id');
    }

    protected function casts(): array
    {
        return ['status' => PickupCredentialStatus::class, 'display_code' => 'encrypted', 'token_secret' => 'encrypted', 'format_version' => 'integer', 'failed_attempts' => 'integer', 'issued_at' => 'datetime', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime', 'locked_until' => 'datetime'];
    }
}
