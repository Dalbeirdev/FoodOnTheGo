<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Auth\AccessControl;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\RestaurantMembershipResource;
use App\Http\Support\ListQuery;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Restaurant\RestaurantStaffService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The staff of a restaurant organization: who belongs to it, with which role, at which locations.
 *
 *  - Reading needs restaurant.staff.view. Someone who holds it for some locations only sees the members who
 *    work at those locations, and only those locations of each member.
 *  - Every change needs restaurant.staff.manage for the WHOLE organization.
 *  - The rules no permission overrides (no change to your own membership, no granting beyond your own
 *    permissions, the last owner stays, locations must belong to the organization) are RestaurantStaffService.
 *
 * The organization always comes from the URL and is checked against the caller's memberships; a membership id
 * of another organization is a 404.
 */
class StaffController extends Controller
{
    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly AccessControl $control,
        private readonly RestaurantStaffService $staff,
    ) {}

    /**
     * Members that are not revoked (filter[status]=REVOKED lists the revoked ones), oldest first.
     */
    public function index(Request $request, RestaurantOrganization $organization): AnonymousResourceCollection
    {
        /** @var RestaurantUser $user */
        $user = $request->user();
        $this->access->membership($user, $organization);
        $visible = $this->visibleLocationIds($user, $organization);

        $list = new ListQuery($request, filterable: ['status'], sortable: ['created_at', 'status'], defaultSort: 'created_at');
        $query = RestaurantMembership::query()->with(['user', 'role', 'locations'])->where('organization_id', $organization->getKey());
        if (! array_key_exists('status', $list->filters())) {
            $query->where('status', '!=', MembershipStatus::Revoked->value);
        }
        if ($visible !== null) {
            $query->where(fn (Builder $q) => $q->where('all_locations', true)
                ->orWhereExists(fn ($at) => $at->selectRaw('1')->from('restaurant_membership_locations as ml')->whereColumn('ml.membership_id', 'restaurant_memberships.id')->whereIn('ml.location_id', $visible)));
        }

        $page = $list->paginate($query);
        if ($visible !== null) {
            $page->getCollection()->each(fn (RestaurantMembership $m) => $m->setRelation('locations', $m->locations->whereIn('id', $visible)->values()));
        }

        return RestaurantMembershipResource::collection($page);
    }

    /**
     * The restaurant roles and what each one allows, for the invitation and role forms.
     */
    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->with('permissions')->orderBy('id')->get()
            ->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name, 'permissions' => $role->permissions->pluck('permission')->sort()->values()->all()])->all()]);
    }

    public function store(Request $request, RestaurantOrganization $organization): JsonResponse
    {
        $this->access->authorizeOrganization($request->user(), $organization, Permission::RestaurantStaffManage);

        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:60'],
            'all_locations' => ['required', 'boolean'],
            'location_ids' => ['sometimes', 'array', 'max:200'],
            'location_ids.*' => ['uuid', 'distinct'],
        ]);
        $all = filter_var($input['all_locations'], FILTER_VALIDATE_BOOLEAN);

        $membership = $this->staff->invite(
            $organization, $input['name'], $input['email'], $this->role($input['role']), $all,
            $all ? new Collection : $this->locations($organization, $input['location_ids'] ?? []), $request->user(),
        );

        return (new RestaurantMembershipResource($membership->load(['user', 'role', 'locations'])))->response()->setStatusCode(201);
    }

    /**
     * Role, location access and status (ACTIVE ⇄ SUSPENDED) of a member — together or one at a time.
     */
    public function update(Request $request, RestaurantOrganization $organization, RestaurantMembership $membership): RestaurantMembershipResource
    {
        $this->access->authorizeOrganization($request->user(), $organization, Permission::RestaurantStaffManage);
        $this->assertOwned($organization, $membership);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'role' => ['sometimes', 'string', 'max:60'],
            'all_locations' => ['sometimes', 'boolean'],
            'location_ids' => ['sometimes', 'array', 'max:200'],
            'location_ids.*' => ['uuid', 'distinct'],
            'status' => ['sometimes', Rule::in([MembershipStatus::Active->value, MembershipStatus::Suspended->value])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $change = ['version' => (int) $input['version'], 'reason' => $input['reason'] ?? null];
        if (isset($input['role'])) {
            $change['role'] = $this->role($input['role']);
        }
        if (array_key_exists('all_locations', $input)) {
            $change['all_locations'] = filter_var($input['all_locations'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('location_ids', $input)) {
            $change['locations'] = $this->locations($organization, $input['location_ids']);
            // Listing locations means "these locations", unless the request says otherwise.
            $change['all_locations'] ??= false;
        }
        if (isset($input['status'])) {
            $change['status'] = MembershipStatus::from($input['status']);
        }

        return new RestaurantMembershipResource($this->staff->update($membership, $change, $request->user())->load(['user', 'role', 'locations']));
    }

    /**
     * Revokes the member's access to this organization. Takes effect on their next request.
     */
    public function destroy(Request $request, RestaurantOrganization $organization, RestaurantMembership $membership): RestaurantMembershipResource
    {
        $this->access->authorizeOrganization($request->user(), $organization, Permission::RestaurantStaffManage);
        $this->assertOwned($organization, $membership);

        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return new RestaurantMembershipResource($this->staff->revoke($membership, $request->user(), $input['reason'] ?? null)->load(['user', 'role', 'locations']));
    }

    public function resendInvitation(Request $request, RestaurantOrganization $organization, RestaurantMembership $membership): JsonResponse
    {
        $this->access->authorizeOrganization($request->user(), $organization, Permission::RestaurantStaffManage);
        $this->assertOwned($organization, $membership);

        $this->staff->resendInvitation($membership);

        return response()->json(['message' => 'A new invitation link has been sent. Earlier links no longer work.']);
    }

    /**
     * Public: the invited person accepts with the single-use link they were sent. A new account chooses its
     * password here; an existing account needs none.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['sometimes', 'nullable', 'string', 'confirmed', 'max:255', Password::min((int) config('auth_security.password.min_length'))],
        ]);

        $membership = $this->staff->acceptInvitation($input['token'], $input['password'] ?? null);

        return response()->json(['message' => 'The invitation is accepted. You can sign in now.', 'restaurant' => $membership->organization->display_name]);
    }

    /**
     * Location ids of this organization where the caller may see staff; null = the whole organization.
     *
     * @return list<int>|null
     */
    private function visibleLocationIds(RestaurantUser $user, RestaurantOrganization $organization): ?array
    {
        if ($this->control->allows($user, Permission::RestaurantStaffView, $organization->scope())) {
            return null;
        }

        $ids = $this->access->locations($user)->with('organization')->where('restaurant_locations.organization_id', $organization->getKey())->get()
            ->filter(fn (RestaurantLocation $l): bool => $this->control->allows($user, Permission::RestaurantStaffView, $l->scope()))
            ->map(fn (RestaurantLocation $l): int => (int) $l->getKey())->values()->all();

        if ($ids === []) {
            throw new AuthorizationException;
        }

        return $ids;
    }

    private function role(string $code): Role
    {
        return Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', $code)->first()
            ?? throw ValidationException::withMessages(['role' => ['Unknown restaurant role.']]);
    }

    /**
     * Locations named by public id — every one must belong to this organization.
     *
     * @param  list<string>  $publicIds
     * @return Collection<int, RestaurantLocation>
     */
    private function locations(RestaurantOrganization $organization, array $publicIds): Collection
    {
        $locations = RestaurantLocation::query()->where('restaurant_locations.organization_id', $organization->getKey())->whereIn('restaurant_locations.public_id', $publicIds)->get();

        if ($locations->count() !== count(array_unique($publicIds))) {
            throw ValidationException::withMessages(['location_ids' => ['Every location must belong to this restaurant.']]);
        }

        return $locations;
    }

    private function assertOwned(RestaurantOrganization $organization, RestaurantMembership $membership): void
    {
        if ((int) $membership->organization_id !== (int) $organization->getKey()) {
            throw ApiException::notFound('not_found', 'The requested resource was not found.');
        }
    }
}
