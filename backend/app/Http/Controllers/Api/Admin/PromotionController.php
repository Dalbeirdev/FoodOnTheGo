<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Permission;
use App\Enums\PromotionScope;
use App\Enums\PromotionStatus;
use App\Enums\PromotionType;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Checkout\FinancialAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Promotions for platform administrators (Module 29) — the real backend behind the admin Promotions screen.
 *
 * admin.promotions.view lists and reads; admin.promotions.manage creates, edits and changes the status. A permission
 * held for one market covers the promotions of that market and of its restaurants; a platform-wide promotion needs
 * the permission platform-wide. Restaurant staff have no promotion endpoint at all: restaurant-created discounts are
 * not approved. Every change needs a reason and is audited.
 */
class PromotionController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(private readonly FinancialAdminService $admin) {}

    public function index(Request $request): JsonResponse
    {
        $visible = $this->marketsWith($request, Permission::AdminPromotionsView);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $now = now();

        $query = Promotion::query()->with(['market', 'organization', 'location'])
            // A market-scoped administrator sees the promotions of those markets — not the platform-wide ones.
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id')));
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }

        $list = new ListQuery(
            $request,
            filterable: ['scope', 'type'],
            sortable: ['code', 'name', 'created_at', 'updated_at', 'starts_at', 'ends_at'],
            defaultSort: '-created_at',
            handlers: [
                // What the promotion IS at this moment: SCHEDULED and EXPIRED follow from the clock.
                'status' => function (Builder $q, string $value) use ($now): void {
                    match (PromotionStatus::tryFrom($value) ?? throw ValidationException::withMessages(['filter.status' => ['Unknown status.']])) {
                        PromotionStatus::Scheduled => $q->where('status', 'ACTIVE')->where('starts_at', '>', $now),
                        PromotionStatus::Expired => $q->where('status', 'ACTIVE')->where('ends_at', '<=', $now),
                        PromotionStatus::Active => $q->where('status', 'ACTIVE')->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
                        default => $q->where('status', $value),
                    };
                },
                'market' => fn (Builder $q, string $value) => $q->whereIn('market_id', DB::table('markets')->where('public_id', $this->uuid('market', $value))->select('id')),
                'restaurant' => fn (Builder $q, string $value) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', $this->uuid('restaurant', $value))->select('id')),
                // Usable on that day (a date, taken as the whole day in UTC).
                'date' => fn (Builder $q, string $value) => $q
                    ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $this->day($value)->endOfDay()))
                    ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $this->day($value)->startOfDay())),
            ],
        );
        $page = $list->paginate($query);
        $held = $this->heldCounts($page->getCollection()->modelKeys());

        return JsonResource::collection($page->through(fn (Promotion $p): array => $this->present($p, (int) ($held[$p->getKey()] ?? 0))))->response();
    }

    public function show(Promotion $promotion): JsonResponse
    {
        $this->authorizeFor(Permission::AdminPromotionsView, $promotion->market);

        return response()->json(['promotion' => $this->present($promotion->load(['market', 'organization', 'location']), (int) ($this->heldCounts([$promotion->getKey()])[$promotion->getKey()] ?? 0))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, creating: true);
        $input = [...$input, ...$this->targets($input)];
        $this->authorizeFor(Permission::AdminPromotionsManage, $this->marketOf($input));

        $promotion = $this->admin->createPromotion($input, $request->user());

        return response()->json(['promotion' => $this->present($promotion->load(['market', 'organization', 'location']), 0)], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeFor(Permission::AdminPromotionsManage, $promotion->market);
        $input = $this->validated($request, creating: false);
        if (array_intersect_key($input, array_flip(['scope', 'market_id', 'organization_id', 'location_id'])) !== []) {
            $input = [...['scope' => $promotion->scope->value], ...$input];
            $input = [...$input, ...$this->targets($input)];
            // Moving a promotion into another market needs the permission there too.
            $this->authorizeFor(Permission::AdminPromotionsManage, $this->marketOf($input));
        }

        $promotion = $this->admin->updatePromotion($promotion, $input, $request->user());

        return response()->json(['promotion' => $this->present($promotion->load(['market', 'organization', 'location']), (int) ($this->heldCounts([$promotion->getKey()])[$promotion->getKey()] ?? 0))]);
    }

    public function status(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeFor(Permission::AdminPromotionsManage, $promotion->market);
        $input = $request->validate([
            'status' => ['required', 'string', Rule::in(array_map(fn (PromotionStatus $s): string => $s->value, PromotionStatus::STORED))],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $promotion = $this->admin->setPromotionStatus($promotion, $input, $request->user());

        return response()->json(['promotion' => $this->present($promotion->load(['market', 'organization', 'location']), (int) ($this->heldCounts([$promotion->getKey()])[$promotion->getKey()] ?? 0))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'version' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'reason' => [$creating ? 'sometimes' : 'required', 'string', 'min:3', 'max:500'],
            'code' => [$need, 'string', 'min:3', 'max:40', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/'],
            'name' => [$need, 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'type' => [$need, 'string', Rule::enum(PromotionType::class)],
            'scope' => [$need, 'string', Rule::enum(PromotionScope::class)],
            'market_id' => ['sometimes', 'nullable', 'uuid'],
            'organization_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/\A[A-Za-z]{3}\z/'],
            // Integers only: minor units and basis points. A float is not an amount.
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'percent_basis_points' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'maximum_discount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'minimum_spend_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'per_customer_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'funding_source' => ['sometimes', 'string', Rule::in(['PLATFORM', 'RESTAURANT', 'SHARED'])],
            // Decided by the server.
            'status' => ['prohibited'], 'redeemed_count' => ['prohibited'], 'id' => ['prohibited'],
        ], ['prohibited' => 'The :attribute field cannot be set here.']);
    }

    /**
     * The models the scope targets name.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function targets(array $input): array
    {
        $find = fn (string $class, string $key) => empty($input[$key]) ? null : ($class::query()->where('public_id', $input[$key])->first() ?? throw ValidationException::withMessages([$key => ['Not found.']]));

        return ['market' => $find(Market::class, 'market_id'), 'organization' => $find(RestaurantOrganization::class, 'organization_id'), 'location' => $find(RestaurantLocation::class, 'location_id')];
    }

    /**
     * The market a promotion with these targets belongs to (null = platform-wide).
     *
     * @param  array<string, mixed>  $input
     */
    private function marketOf(array $input): ?Market
    {
        return match (PromotionScope::from((string) $input['scope'])) {
            PromotionScope::Platform => null,
            PromotionScope::Market => $input['market'] ?? throw ValidationException::withMessages(['market_id' => ['A market promotion needs its market.']]),
            PromotionScope::RestaurantOrganization => Market::query()->find(($input['organization'] ?? throw ValidationException::withMessages(['organization_id' => ['An organization promotion needs its organization.']]))->primary_market_id),
            PromotionScope::RestaurantLocation => ($input['location'] ?? throw ValidationException::withMessages(['location_id' => ['A restaurant promotion needs its restaurant.']]))->market,
        };
    }

    private function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
    }

    /**
     * Uses currently held by confirmed checkouts, per promotion.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function heldCounts(array $ids): array
    {
        return $ids === [] ? [] : PromotionReservation::query()->whereIn('promotion_id', $ids)->where('status', PromotionReservation::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('promotion_id')->selectRaw('promotion_id, count(*) as n')->pluck('n', 'promotion_id')->map(fn ($n): int => (int) $n)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Promotion $promotion, int $held): array
    {
        $iso = fn ($instant): ?string => $instant?->utc()->toIso8601String();

        return [
            'id' => (string) $promotion->public_id,
            'code' => (string) $promotion->code,
            'name' => (string) $promotion->name,
            'description' => $promotion->description,
            'type' => $promotion->type->value,
            // The stored life-cycle state, and what it means right now (SCHEDULED / EXPIRED follow from the clock).
            'status' => $promotion->status->value,
            'effective_status' => $promotion->effectiveStatus()->value,
            'scope' => $promotion->scope->value,
            'market' => $promotion->market === null ? null : ['id' => (string) $promotion->market->public_id, 'name' => (string) $promotion->market->name, 'country_code' => (string) $promotion->market->country_code],
            'organization' => $promotion->organization === null ? null : ['id' => (string) $promotion->organization->public_id, 'name' => (string) $promotion->organization->display_name],
            'location' => $promotion->location === null ? null : ['id' => (string) $promotion->location->public_id, 'name' => (string) $promotion->location->name],
            'currency' => $promotion->currency,
            'amount_minor' => $promotion->amount_minor,
            'percent_basis_points' => $promotion->percent_basis_points,
            'maximum_discount_minor' => $promotion->maximum_discount_minor,
            'minimum_spend_minor' => $promotion->minimum_spend_minor,
            'starts_at' => $iso($promotion->starts_at),
            'ends_at' => $iso($promotion->ends_at),
            'usage_limit' => $promotion->usage_limit,
            'per_customer_limit' => $promotion->per_customer_limit,
            'redeemed_count' => (int) $promotion->redeemed_count,
            'held_count' => $held,
            'funding_source' => (string) $promotion->funding_source,
            'stacking' => 'NOT_APPROVED',
            'version' => (int) $promotion->version,
            'created_at' => $iso($promotion->created_at),
            'updated_at' => $iso($promotion->updated_at),
        ];
    }

    private function uuid(string $filter, string $value): string
    {
        return preg_match('/\A[0-9a-fA-F-]{36}\z/', $value) === 1 ? $value : throw ValidationException::withMessages(["filter.{$filter}" => ['Must be an id.']]);
    }

    private function day(string $value): CarbonImmutable
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 ? CarbonImmutable::parse($value, 'UTC') : throw ValidationException::withMessages(['filter.date' => ['Must be a date (YYYY-MM-DD).']]);
    }
}
