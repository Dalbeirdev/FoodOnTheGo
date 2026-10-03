<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One restaurant user's membership of one organization: the role held there and the locations it applies to
 * (`all_locations`, or the listed ones). Nothing is mass-assignable — memberships change only through
 * RestaurantStaffService, which also keeps the role assignments (permission + scope) in step.
 */
class RestaurantMembership extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    /**
     * @return BelongsTo<RestaurantUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(RestaurantUser::class, 'restaurant_user_id');
    }

    /**
     * @return BelongsTo<RestaurantOrganization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The listed locations (only meaningful when `all_locations` is false).
     *
     * @return BelongsToMany<RestaurantLocation, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantLocation::class, 'restaurant_membership_locations', 'membership_id', 'location_id');
    }

    /**
     * @return HasMany<RestaurantStaffInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(RestaurantStaffInvitation::class, 'membership_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => MembershipStatus::class, 'all_locations' => 'boolean', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'version' => 'integer'];
    }
}
