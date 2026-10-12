<?php

namespace App\Models;

use App\Enums\CommissionBasePolicy;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commercial terms (Module 36): a commission percentage with an explicit base, for a market, an organization or a
 * location, between two moments. A plan is never edited — its rate, base and start are facts orders were allocated
 * with. New terms are a new plan that follows it.
 */
class CommissionPlan extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const SCOPE_MARKET = 'MARKET';

    public const SCOPE_ORGANIZATION = 'ORGANIZATION';

    public const SCOPE_LOCATION = 'LOCATION';

    public const APPROVAL_TEST = 'TEST';

    public const APPROVAL_APPROVED = 'APPROVED';

    public const SOURCE_ADMIN = 'ADMIN';

    public const SOURCE_FIXTURE = 'FIXTURE';

    protected $guarded = ['*'];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    /** SCHEDULED, ACTIVE, ENDED or WITHDRAWN at the given moment. */
    public function stateAt(CarbonInterface $at): string
    {
        if ($this->withdrawn_at !== null) {
            return 'WITHDRAWN';
        }
        if ($this->effective_from->greaterThan($at)) {
            return 'SCHEDULED';
        }

        return $this->effective_until !== null && $this->effective_until->lessThanOrEqualTo($at) ? 'ENDED' : 'ACTIVE';
    }

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'base_policy' => CommissionBasePolicy::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'withdrawn_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
