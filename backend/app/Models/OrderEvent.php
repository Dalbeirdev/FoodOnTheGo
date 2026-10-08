<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order's timeline. Append-only: written once, never changed or removed.
 */
class OrderEvent extends Model
{
    use StoresUtcTimestamps;

    public const UPDATED_AT = null;

    public const ACTOR_SYSTEM = 'SYSTEM';

    public const ACTOR_CUSTOMER = 'CUSTOMER';

    public const ACTOR_RESTAURANT = 'RESTAURANT_USER';

    public const ACTOR_ADMIN = 'ADMIN';

    public const ORDER_CREATED = 'ORDER_CREATED';

    public const WAITING_FOR_RESTAURANT = 'WAITING_FOR_RESTAURANT';

    public const RESTAURANT_ACCEPTED = 'RESTAURANT_ACCEPTED';

    public const RESTAURANT_REJECTED = 'RESTAURANT_REJECTED';

    public const PREPARATION_STARTED = 'PREPARATION_STARTED';

    public const READY_FOR_PICKUP = 'READY_FOR_PICKUP';

    public const CUSTOMER_PICKED_UP = 'CUSTOMER_PICKED_UP';

    public const ORDER_COMPLETED = 'ORDER_COMPLETED';

    public const ORDER_CANCELLED = 'ORDER_CANCELLED';

    public const REFUND_REQUIRED = 'REFUND_REQUIRED';

    public const RECOVERY_REQUIRED = 'RECOVERY_REQUIRED';

    public const STEP_REFUSED = 'STEP_REFUSED';

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['detail' => 'array', 'customer_visible' => 'boolean', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
