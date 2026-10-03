<?php

namespace App\Services\Restaurant;

use App\Auth\AccessControl;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant and location isolation for the restaurant dashboard API — the one place every restaurant endpoint
 * asks "may this person touch this restaurant?".
 *
 * Two questions, answered separately:
 *
 *   1. Is the record theirs at all?   A location or organization outside the user's ACTIVE memberships does
 *      not exist for them: 404, the same answer as for an id that was never issued. Nothing a client sends
 *      (an id in the URL, the body or a header) can widen this — the set comes from the database.
 *   2. May they do this to it?        The permission must be held for that exact resource scope
 *      (AccessControl: permission + organization / location). Missing → 403.
 *
 * A membership limited to some locations reaches only those: another location of the same organization is
 * as invisible as another restaurant's.
 */
final class RestaurantAccess
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * The user's ACTIVE memberships with what each one needs to be shown.
     *
     * @return Collection<int, RestaurantMembership>
     */
    public function memberships(RestaurantUser $user): Collection
    {
        return RestaurantMembership::query()
            ->with(['organization', 'role'])
            ->where('restaurant_user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * Every location the user may open: all locations of organizations where the membership covers all of
     * them, plus the locations listed on the others.
     *
     * @return Builder<RestaurantLocation>
     */
    public function locations(RestaurantUser $user): Builder
    {
        $active = fn (bool $all) => DB::table('restaurant_memberships')
            ->where('restaurant_user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->where('all_locations', $all);

        return RestaurantLocation::query()->where(fn (Builder $query) => $query
            ->whereIn('restaurant_locations.organization_id', $active(true)->select('organization_id'))
            ->orWhereIn('restaurant_locations.id', DB::table('restaurant_membership_locations')
                ->whereIn('membership_id', $active(false)->select('id'))->select('location_id')));
    }

    public function reaches(RestaurantUser $user, RestaurantLocation $location): bool
    {
        return $this->locations($user)->where('restaurant_locations.id', $location->getKey())->exists();
    }

    /**
     * @throws ApiException 404 when the location is not one of the user's
     * @throws AuthorizationException 403 when it is, but the permission is not held for it
     */
    public function authorize(RestaurantUser $user, RestaurantLocation $location, Permission $permission): void
    {
        if (! $this->reaches($user, $location)) {
            throw self::notFound();
        }

        $location->loadMissing('organization');
        if (! $this->access->allows($user, $permission, $location->scope())) {
            throw new AuthorizationException;
        }
    }

    /**
     * The user's ACTIVE membership of the organization — 404 when there is none.
     */
    public function membership(RestaurantUser $user, RestaurantOrganization $organization): RestaurantMembership
    {
        return RestaurantMembership::query()
            ->with(['role', 'locations'])
            ->where('restaurant_user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->first() ?? throw self::notFound();
    }

    /**
     * A permission that must be held for the whole organization (staff management).
     */
    public function authorizeOrganization(RestaurantUser $user, RestaurantOrganization $organization, Permission $permission): RestaurantMembership
    {
        $membership = $this->membership($user, $organization);

        if (! $this->access->allows($user, $permission, $organization->scope())) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    /**
     * Permission codes the user holds for this location (for client navigation; never a decision).
     *
     * @return list<string>
     */
    public function permissions(RestaurantUser $user, RestaurantLocation $location): array
    {
        $location->loadMissing('organization');

        return $this->access->permissionsIn($user, $location->scope());
    }

    private static function notFound(): ApiException
    {
        return ApiException::notFound('not_found', 'The requested resource was not found.');
    }
}
