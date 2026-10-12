<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Enums\CommissionBasePolicy;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one order's money is made of (Module 36): the customer's total, the merchandise, who pays for the discount,
 * whose the tax and the fees are, the commission terms applied and what follows for the restaurant and the platform.
 *
 * Made once, when the order is created, from the ORDER'S OWN columns and the commercial terms of that moment. It is
 * history: a refund or a correction afterwards is a separate adjustment, never a change here.
 */
class OrderFinancialAllocation extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const REVIEW_NO_PLAN = 'NO_COMMISSION_PLAN';

    public const REVIEW_FUNDING_UNKNOWN = 'PROMOTION_FUNDING_UNKNOWN';

    public const REVIEW_SHARED_SPLIT = 'SHARED_PROMOTION_SPLIT_UNDEFINED';

    public const SOURCE_PLAN = 'PLAN';

    public const SOURCE_DECISION = 'ADMIN_DECISION';

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function commissionPlan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'commission_base_policy' => CommissionBasePolicy::class,
            'customer_total_minor' => 'integer', 'merchandise_gross_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer',
            'tax_included_minor' => 'integer', 'fee_minor' => 'integer', 'restaurant_discount_minor' => 'integer', 'platform_discount_minor' => 'integer',
            'tax_restaurant_minor' => 'integer', 'tax_held_minor' => 'integer', 'fee_restaurant_minor' => 'integer', 'fee_platform_minor' => 'integer',
            'commission_rate_bps' => 'integer', 'commission_base_minor' => 'integer', 'commission_minor' => 'integer', 'commission_plan_version' => 'integer',
            'restaurant_net_minor' => 'integer', 'platform_net_minor' => 'integer', 'version' => 'integer',
            'placed_at' => 'datetime', 'recognized_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }
}
