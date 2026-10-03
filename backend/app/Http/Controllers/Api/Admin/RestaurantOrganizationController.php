<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\RestaurantStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\AdminRestaurantOrganizationResource;
use App\Http\Resources\Restaurant\RestaurantMembershipResource;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Models\RestaurantAdminNote;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\Role;
use App\Services\Restaurant\RestaurantAdminService;
use App\Services\Restaurant\RestaurantLifecycleService;
use App\Services\Restaurant\RestaurantStaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Restaurant organizations for administrators. There is no public way to create an operating restaurant: an
 * administrator creates the organization and its locations as DRAFT, invites the first owner, and the
 * approval lifecycle decides when customers see anything.
 *
 * An organization is authorised through its primary market.
 */
class RestaurantOrganizationController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(private readonly RestaurantAdminService $restaurants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $visible = $this->marketsWith($request, Permission::AdminRestaurantsView);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);

        // The count is a plain sub-select: the location model's own select (coordinates) must not end up inside it.
        $query = RestaurantOrganization::query()->with('primaryMarket')->select('restaurant_organizations.*')
            ->selectSub(DB::table('restaurant_locations')->selectRaw('count(*)')->whereColumn('restaurant_locations.organization_id', 'restaurant_organizations.id'), 'locations_count')
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('primary_market_id', Market::query()->whereIn('public_id', $visible)->select('id')));
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('display_name', 'ilike', $like)->orWhere('legal_name', 'ilike', $like)->orWhere('slug', 'ilike', $like));
        }

        $list = new ListQuery($request, filterable: ['status'], sortable: ['display_name', 'status', 'created_at', 'updated_at'], defaultSort: 'display_name', handlers: [
            'market' => function (Builder $q, string $value): void {
                if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
                    throw ValidationException::withMessages(['filter.market' => ['This filter takes an id.']]);
                }
                $q->whereIn('primary_market_id', DB::table('markets')->where('public_id', $value)->select('id'));
            },
        ]);

        return AdminRestaurantOrganizationResource::collection($list->paginate($query));
    }

    public function show(RestaurantOrganization $organization): JsonResponse
    {
        $this->marketOf($organization, Permission::AdminRestaurantsView);

        return response()->json($this->detail($organization));
    }

    /**
     * Creates a DRAFT organization in a market. It has no locations and no staff yet.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'market_id' => ['required', 'uuid'],
            'legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'market_scope' => ['sometimes', Rule::in([RestaurantOrganization::SINGLE_MARKET, RestaurantOrganization::MULTI_MARKET])],
        ]);
        $market = self::market($input['market_id']);
        $this->authorizeMarket(Permission::AdminRestaurantsManage, $market);

        return response()->json($this->detail($this->restaurants->createOrganization($market, $input, $request->user())), 201);
    }

    public function update(Request $request, RestaurantOrganization $organization): JsonResponse
    {
        $this->marketOf($organization, Permission::AdminRestaurantsManage);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'legal_name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'display_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'market_scope' => ['sometimes', Rule::in([RestaurantOrganization::SINGLE_MARKET, RestaurantOrganization::MULTI_MARKET])],
        ]);

        $this->restaurants->updateOrganization($organization, $input, $request->user());

        return response()->json($this->detail($organization));
    }

    /**
     * Lifecycle of the organization. Suspending it takes every location away from customers at once, without
     * rewriting the locations' own statuses; reactivating brings back exactly the ones that were approved.
     */
    public function status(Request $request, RestaurantOrganization $organization, RestaurantLifecycleService $lifecycle): JsonResponse
    {
        $this->marketOf($organization, Permission::AdminRestaurantsView);
        $input = $request->validate(RestaurantController::statusRules());
        $target = RestaurantStatus::from($input['status']);
        $this->marketOf($organization, $organization->status->permissionFor($target));

        $lifecycle->transition($organization, $target, $input, $request->user());

        return response()->json($this->detail($organization));
    }

    /**
     * Creates a DRAFT location. The coordinates are validated against the market and the city; the region and
     * the service area are resolved by PostGIS — never taken from the request.
     */
    public function storeLocation(Request $request, RestaurantOrganization $organization): JsonResponse
    {
        $this->marketOf($organization, Permission::AdminRestaurantsManage);

        $input = $request->validate([
            'market_id' => ['sometimes', 'uuid'],
            'city_id' => ['sometimes', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'branch_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'formatted_address' => ['required', 'string', 'min:5', 'max:300'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'timezone' => ['sometimes', 'timezone:all'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'public_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
        ]);
        // A location in another market (MULTI_MARKET organizations) also needs the permission for that market.
        $market = isset($input['market_id']) ? self::market($input['market_id']) : Market::query()->findOrFail($organization->primary_market_id);
        $this->authorizeMarket(Permission::AdminRestaurantsManage, $market);

        $location = $this->restaurants->createLocation($organization, $market, RestaurantController::city($market, $input['city_id'] ?? null), $input, $request->user());

        return response()->json(['id' => $location->public_id, 'slug' => $location->slug, 'status' => $location->status->value, 'organization' => $this->detail($organization)], 201);
    }

    /**
     * An internal note — administrators only; never part of a restaurant or customer response.
     */
    public function storeNote(Request $request, RestaurantOrganization $organization): JsonResponse
    {
        $this->marketOf($organization, Permission::AdminRestaurantsManage);

        $input = $request->validate([
            'note' => ['required', 'string', 'min:2', 'max:2000'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $location = isset($input['location_id'])
            ? (RestaurantLocation::query()->where('restaurant_locations.organization_id', $organization->getKey())->where('restaurant_locations.public_id', $input['location_id'])->first()
                ?? throw ValidationException::withMessages(['location_id' => ['The location does not belong to this organization.']]))
            : null;

        $note = $this->restaurants->addNote($organization, $location, $input['note'], $request->user());

        return response()->json(['id' => $note->public_id, 'note' => $note->note, 'author' => $request->user()->name, 'about' => $location === null ? 'organization' : 'location', 'created_at' => $note->created_at->toIso8601String()], 201);
    }

    /**
     * Invites a member for the whole organization — how the first owner of a new restaurant gets in. The
     * person receives a single-use link; nobody chooses a password for them.
     */
    public function inviteStaff(Request $request, RestaurantOrganization $organization, RestaurantStaffService $staff): JsonResponse
    {
        $this->marketOf($organization, Permission::AdminRestaurantsManage);

        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['sometimes', 'string', 'max:60'],
        ]);
        $role = Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', $input['role'] ?? 'OWNER')->first()
            ?? throw ValidationException::withMessages(['role' => ['Unknown restaurant role.']]);

        $membership = $staff->invite($organization, $input['name'], $input['email'], $role, true, new Collection, $request->user());

        return (new RestaurantMembershipResource($membership->load(['user', 'role', 'locations'])))->response()->setStatusCode(201);
    }

    public function revokeStaff(Request $request, RestaurantOrganization $organization, RestaurantMembership $membership, RestaurantStaffService $staff): RestaurantMembershipResource
    {
        $this->marketOf($organization, Permission::AdminRestaurantsManage);
        if ((int) $membership->organization_id !== (int) $organization->getKey()) {
            throw ApiException::notFound('not_found', 'The requested resource was not found.');
        }

        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new RestaurantMembershipResource($staff->revoke($membership, $request->user(), $input['reason'])->load(['user', 'role', 'locations']));
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(RestaurantOrganization $organization): array
    {
        $organization = RestaurantOrganization::query()->with(['primaryMarket', 'locations.city'])->whereKey($organization->getKey())->firstOrFail();
        $request = request();

        return [
            ...(new AdminRestaurantOrganizationResource($organization))->resolve($request),
            'staff' => RestaurantMembershipResource::collection(RestaurantMembership::query()->with(['user', 'role', 'locations'])
                ->where('organization_id', $organization->getKey())->where('status', '!=', MembershipStatus::Revoked->value)->orderBy('id')->get())->resolve($request),
            'has_owner' => app(RestaurantStaffService::class)->hasOwner($organization),
            'notes' => RestaurantAdminNote::query()->with('author')->where('organization_id', $organization->getKey())->orderByDesc('id')->limit(100)->get()
                ->map(fn (RestaurantAdminNote $n): array => [
                    'id' => $n->public_id, 'note' => $n->note, 'author' => $n->author?->name, 'about' => $n->location_id === null ? 'organization' : 'location', 'created_at' => $n->created_at->toIso8601String(),
                ])->all(),
        ];
    }

    private function marketOf(RestaurantOrganization $organization, Permission $permission): Market
    {
        $market = Market::query()->findOrFail($organization->primary_market_id);
        $this->authorizeMarket($permission, $market);

        return $market;
    }

    private static function market(string $publicId): Market
    {
        return Market::query()->where('public_id', $publicId)->first() ?? throw ValidationException::withMessages(['market_id' => ['Unknown market.']]);
    }
}
