<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\RejectionCategory;
use App\Enums\RestaurantStatus;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Api\Restaurant\Concerns\LoadsRestaurantLocations;
use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\AdminRestaurantResource;
use App\Http\Resources\Restaurant\AdminRestaurantSummaryResource;
use App\Http\Resources\Restaurant\RestaurantMembershipResource;
use App\Http\Support\ListQuery;
use App\Models\AuditEvent;
use App\Models\City;
use App\Models\Market;
use App\Models\RestaurantAdminNote;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use App\Services\Restaurant\RestaurantAdminService;
use App\Services\Restaurant\RestaurantLifecycleService;
use App\Services\Restaurant\RestaurantStaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Restaurant locations for administrators: the list, one location in full, its placement and its place in
 * the approval lifecycle. A location is always authorised through the market it is in, so an administrator
 * whose role is limited to one market neither sees nor changes a restaurant of another.
 *
 * Which permission a status change needs depends on the change (RestaurantStatus::permissionFor):
 * reviewing / approving / rejecting, suspending / reactivating, and plain management are separate permissions.
 */
class RestaurantController extends Controller
{
    use AuthorizesMarketScope, LoadsRestaurantLocations;

    /** filter[stage]: the working queues of the review screen. INACTIVE = not in the pipeline (drafts and deactivated). */
    private const STAGES = [
        'PENDING' => ['SUBMITTED', 'UNDER_REVIEW'],
        'APPROVED' => ['APPROVED'],
        'REJECTED' => ['REJECTED'],
        'SUSPENDED' => ['SUSPENDED'],
        'INACTIVE' => ['INACTIVE', 'DRAFT'],
        'DRAFT' => ['DRAFT'],
    ];

    /**
     * Filters: filter[status], filter[stage] (PENDING = submitted + under review), filter[market], filter[city],
     * filter[organization] (public ids); ?q= matches the location, its branch label, slug or organization.
     * `counts` gives the number of locations per status in the caller's scope, for the queue tabs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $visible = $this->marketsWith($request, Permission::AdminRestaurantsView);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);

        $scoped = fn (): Builder => RestaurantLocation::query()
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('restaurant_locations.market_id', Market::query()->whereIn('public_id', $visible)->select('id')));

        $query = $scoped()->with(['organization', 'market', 'city', 'region', 'cuisines']);
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            // Bound parameter; LIKE wildcards are escaped so the text can only match literally.
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('restaurant_locations.name', 'ilike', $like)
                ->orWhere('restaurant_locations.branch_label', 'ilike', $like)
                ->orWhere('restaurant_locations.slug', 'ilike', $like)
                ->orWhereExists(fn ($org) => $org->selectRaw('1')->from('restaurant_organizations as o')->whereColumn('o.id', 'restaurant_locations.organization_id')
                    ->where(fn ($name) => $name->where('o.display_name', 'ilike', $like)->orWhere('o.legal_name', 'ilike', $like))));
        }

        $list = new ListQuery(
            $request,
            filterable: ['status'],
            sortable: ['name', 'status', 'created_at', 'updated_at', 'submitted_at'],
            defaultSort: '-created_at',
            handlers: [
                'stage' => function (Builder $q, string $value): void {
                    $q->whereIn('restaurant_locations.status', self::STAGES[$value] ?? throw ValidationException::withMessages(['filter.stage' => ['Unknown stage.']]));
                },
                'market' => fn (Builder $q, string $value) => $q->whereIn('restaurant_locations.market_id', DB::table('markets')->where('public_id', $this->uuid('market', $value))->select('id')),
                'city' => fn (Builder $q, string $value) => $q->whereIn('restaurant_locations.city_id', DB::table('cities')->where('public_id', $this->uuid('city', $value))->select('id')),
                'organization' => fn (Builder $q, string $value) => $q->whereIn('restaurant_locations.organization_id', DB::table('restaurant_organizations')->where('public_id', $this->uuid('organization', $value))->select('id')),
            ],
        );

        $counts = $scoped()->withoutGlobalScope('coordinates')->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $page = $list->paginate($query);
        // How many locations each organization on this page has: one grouped query, not one per row.
        $sizes = DB::table('restaurant_locations')->whereIn('organization_id', $page->getCollection()->pluck('organization_id')->unique()->all())
            ->groupBy('organization_id')->selectRaw('organization_id, count(*) as n')->pluck('n', 'organization_id');
        $page->getCollection()->each(fn (RestaurantLocation $l) => $l->setAttribute('organization_locations', (int) ($sizes[$l->organization_id] ?? 1)));

        return AdminRestaurantSummaryResource::collection($page)->additional(['counts' => [
            ...array_fill_keys(array_map(fn (RestaurantStatus $s): string => $s->value, RestaurantStatus::cases()), 0),
            ...$counts->map(fn ($n): int => (int) $n)->all(),
        ]]);
    }

    /**
     * One location in full, with what only administrators see: internal notes, the staff of the organization,
     * its other locations, a readiness check for the reviewer and the recent history.
     */
    public function show(RestaurantLocation $location): JsonResponse
    {
        $this->marketOf($location, Permission::AdminRestaurantsView);

        return response()->json($this->detail($location));
    }

    /**
     * Placement and identity of a location: name, address, coordinates (validated against the market, service
     * area resolved again by PostGIS), time zone, currency, phone.
     */
    public function update(Request $request, RestaurantLocation $location, RestaurantAdminService $restaurants): JsonResponse
    {
        $market = $this->marketOf($location, Permission::AdminRestaurantsManage);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'branch_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'formatted_address' => ['sometimes', 'string', 'min:5', 'max:300'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'latitude' => ['required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['required_with:latitude', 'numeric', 'between:-180,180'],
            'city_id' => ['sometimes', 'uuid'],
            'timezone' => ['sometimes', 'timezone:all'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $restaurants->updateLocation($location, self::city($market, $input['city_id'] ?? null), $input, $request->user());

        return response()->json($this->detail($location));
    }

    /**
     * Moves the location along its lifecycle: review, approve, reject (category + explanation for the
     * restaurant), suspend, reactivate, deactivate. `reason` is internal; `public_reason` is what the
     * restaurant reads.
     */
    public function status(Request $request, RestaurantLocation $location, RestaurantLifecycleService $lifecycle): JsonResponse
    {
        // Out of scope first (403), then the shape of the request (422), then the permission this particular move needs.
        $this->marketOf($location, Permission::AdminRestaurantsView);
        $input = $request->validate(self::statusRules());
        $target = RestaurantStatus::from($input['status']);
        $this->marketOf($location, $location->status->permissionFor($target));

        $lifecycle->transition($location, $target, $input, $request->user());

        return response()->json($this->detail($location));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function statusRules(): array
    {
        return [
            'status' => ['required', Rule::enum(RestaurantStatus::class)],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'public_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', Rule::enum(RejectionCategory::class)],
        ];
    }

    /**
     * A city named by public id, which must belong to the market.
     */
    public static function city(Market $market, ?string $publicId): ?City
    {
        if ($publicId === null) {
            return null;
        }

        return City::query()->with('region')->where('cities.market_id', $market->getKey())->where('cities.public_id', $publicId)->first()
            ?? throw ValidationException::withMessages(['city_id' => ['The city does not belong to the market of this restaurant.']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(RestaurantLocation $location): array
    {
        $location = $this->fresh($location);
        $organization = $location->organization;
        $request = request();

        $members = RestaurantMembership::query()->with(['user', 'role', 'locations'])->where('organization_id', $organization->getKey())
            ->where('status', '!=', MembershipStatus::Revoked->value)->orderBy('id')->get();
        $siblings = RestaurantLocation::query()->with('city')->where('restaurant_locations.organization_id', $organization->getKey())->orderBy('restaurant_locations.id')->get();

        return [
            ...(new AdminRestaurantResource($location))->resolve($request),
            'organization_locations' => $siblings->map(fn (RestaurantLocation $l): array => [
                'id' => $l->public_id, 'name' => $l->name, 'branch_label' => $l->branch_label, 'city' => $l->city->name, 'status' => $l->status->value,
                'operational_status' => $l->operational_status->value, 'accepting_orders' => $l->accepting_orders, 'timezone' => $l->timezone, 'currency' => $l->currency, 'version' => (int) $l->version,
            ])->all(),
            'staff' => RestaurantMembershipResource::collection($members)->resolve($request),
            'notes' => RestaurantAdminNote::query()->with('author')->where('organization_id', $organization->getKey())
                ->where(fn (Builder $q) => $q->whereNull('location_id')->orWhere('location_id', $location->getKey()))->orderByDesc('id')->limit(100)->get()
                ->map(fn (RestaurantAdminNote $n): array => [
                    'id' => $n->public_id, 'note' => $n->note, 'author' => $n->author?->name, 'about' => $n->location_id === null ? 'organization' : 'location', 'created_at' => $n->created_at->toIso8601String(),
                ])->all(),
            'readiness' => $this->readiness($location, app(RestaurantStaffService::class)->hasOwner($organization)),
            'history' => $this->history([$location->public_id, $organization->public_id, ...$members->pluck('public_id')->all()]),
        ];
    }

    /**
     * What a reviewer wants to know before approving. Informational: approval is the reviewer's decision.
     *
     * @return list<array{check: string, ok: bool}>
     */
    private function readiness(RestaurantLocation $location, bool $hasOwner): array
    {
        return [
            ['check' => 'organization_approved', 'ok' => $location->organization->status->isApproved()],
            ['check' => 'inside_service_area', 'ok' => $location->service_area_id !== null],
            ['check' => 'opening_hours', 'ok' => $location->hours->isNotEmpty()],
            ['check' => 'cuisine', 'ok' => $location->cuisines->isNotEmpty()],
            ['check' => 'pickup_configured', 'ok' => $location->pickupSettings !== null && $location->pickupSettings->pickup_enabled && $location->pickupMethods->contains('enabled', true)],
            ['check' => 'owner_account', 'ok' => $hasOwner],
        ];
    }

    /**
     * Recent audit events of the location, its organization and their staff memberships, newest first.
     *
     * @param  list<string>  $targets
     * @return list<array<string, mixed>>
     */
    private function history(array $targets): array
    {
        $events = AuditEvent::query()->whereIn('target_public_id', $targets)->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();
        $names = [];
        foreach (['admin_user' => 'admin_users', 'restaurant_user' => 'restaurant_users'] as $type => $table) {
            $ids = $events->where('actor_type', $type)->pluck('actor_public_id')->filter()->unique()->all();
            $names[$type] = $ids === [] ? collect() : DB::table($table)->whereIn('public_id', $ids)->pluck('name', 'public_id');
        }

        return $events->map(fn (AuditEvent $e): array => [
            'id' => $e->public_id, 'action' => $e->action, 'actor_type' => $e->actor_type, 'actor_name' => $names[$e->actor_type][$e->actor_public_id] ?? null,
            'reason' => $e->reason, 'changes' => (object) $e->changes, 'occurred_at' => $e->occurred_at->toIso8601String(),
        ])->all();
    }

    private function marketOf(RestaurantLocation $location, Permission $permission): Market
    {
        $market = Market::query()->findOrFail($location->market_id);
        $this->authorizeMarket($permission, $market);

        return $market;
    }

    private function uuid(string $filter, string $value): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            throw ValidationException::withMessages(["filter.{$filter}" => ['This filter takes an id.']]);
        }

        return $value;
    }
}
