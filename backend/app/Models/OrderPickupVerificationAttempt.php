<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to verify a pickup (Module 32), successful or not: who tried, at which location, by which method, with
 * what result. Never the submitted secret or code. Append-only; the order and the credential are known when the
 * attempt reached one.
 */
class OrderPickupVerificationAttempt extends Model
{
    use StoresUtcTimestamps;

    public $timestamps = false;

    public const METHOD_QR = 'QR';

    public const METHOD_MANUAL_CODE = 'MANUAL_CODE';

    public const SUCCESS = 'SUCCESS';

    public const INVALID_CREDENTIAL = 'INVALID_CREDENTIAL';

    public const NOT_READY = 'NOT_READY';

    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const WRONG_LOCATION = 'WRONG_LOCATION';

    public const EXPIRED = 'EXPIRED';

    public const REVOKED = 'REVOKED';

    public const ALREADY_USED = 'ALREADY_USED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const LOCKED = 'LOCKED';

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(OrderPickupCredential::class, 'credential_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(RestaurantUser::class, 'restaurant_user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'restaurant_location_id');
    }

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }
}
