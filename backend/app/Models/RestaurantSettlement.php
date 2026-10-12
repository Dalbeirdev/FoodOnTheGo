<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the platform owes one restaurant location for a period, in one currency (Module 36) — a statement made of
 * lines, each of which leads back to an order, a refund or an adjustment. It is NOT a payment, NOT a provider
 * settlement and NOT a tax invoice.
 */
class RestaurantSettlement extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const BY_SYSTEM = 'SYSTEM';

    public const BY_ADMIN = 'ADMIN';

    protected $guarded = ['*'];

    public function lines(): HasMany
    {
        return $this->hasMany(RestaurantSettlementLine::class, 'settlement_id')->orderBy('line_no');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(RestaurantPayout::class, 'settlement_id')->orderBy('id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'generated_by_admin_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cancelled_by_admin_id');
    }

    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'order_count' => 'integer', 'line_count' => 'integer', 'gross_sales_minor' => 'integer', 'restaurant_discount_minor' => 'integer',
            'commission_minor' => 'integer', 'tax_passed_minor' => 'integer', 'fee_passed_minor' => 'integer', 'refund_adjustments_minor' => 'integer',
            'other_adjustments_minor' => 'integer', 'net_payable_minor' => 'integer', 'paid_minor' => 'integer', 'version' => 'integer',
            'period_start' => 'datetime', 'period_end' => 'datetime', 'generated_at' => 'datetime', 'approved_at' => 'datetime',
            'cancelled_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }
}
