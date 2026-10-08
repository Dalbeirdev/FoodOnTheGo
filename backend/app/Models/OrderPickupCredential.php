<?php

namespace App\Models;

use App\Enums\PickupCredentialStatus;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What proves a pickup (foundation, Module 31): an opaque random token behind the QR code (stored as a hash only)
 * and a short code the customer can read out (stored encrypted, with a hash for lookup). Neither carries a customer,
 * an order number, a payment or an amount. Verification — and consuming it — is Module 32.
 */
class OrderPickupCredential extends Model
{
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['status' => PickupCredentialStatus::class, 'display_code' => 'encrypted', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
