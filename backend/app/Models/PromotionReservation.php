<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a limited promotion held for a confirmed checkout (Module 29), so that two customers cannot both take
 * the last one. Released when the checkout ends without an order; redeemed when an order uses it (Module 31).
 */
class PromotionReservation extends Model
{
    use StoresUtcTimestamps;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RELEASED = 'RELEASED';

    public const STATUS_REDEEMED = 'REDEEMED';

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
