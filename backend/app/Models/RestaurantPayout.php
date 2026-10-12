<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The disbursement of ONE approved settlement to a restaurant (Module 36). Its amount is the settlement's — derived
 * on the server. It is PAID only on the provider's own record of it, or — paid outside — on a recorded bank
 * reference with its evidence. A paid payout is never changed: money that has to come back later is a new
 * adjustment for a later settlement.
 */
class RestaurantPayout extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const METHOD_PROVIDER = 'PROVIDER';

    public const METHOD_EXTERNAL = 'EXTERNAL';

    public const PROVIDER_EXTERNAL = 'external';

    protected $guarded = ['*'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RestaurantSettlement::class, 'settlement_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_id');
    }

    /** A short reference a restaurant can read to support. Derived from the public id; it opens nothing. */
    public function supportReference(): string
    {
        return 'PO-'.strtoupper(substr(str_replace('-', '', (string) $this->public_id), 0, 10));
    }

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer', 'submit_attempts' => 'integer', 'reconcile_runs' => 'integer', 'version' => 'integer',
            'external_paid_on' => 'date',
            'next_action_at' => 'datetime', 'last_reconciled_at' => 'datetime', 'requested_at' => 'datetime', 'submitted_at' => 'datetime',
            'processing_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }
}
