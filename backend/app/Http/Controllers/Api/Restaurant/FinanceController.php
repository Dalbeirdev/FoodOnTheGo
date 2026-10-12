<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Enums\SettlementStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancePresenter;
use App\Models\FinancialHold;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantPayout;
use App\Models\RestaurantPayoutProfile;
use App\Models\RestaurantSettlement;
use App\Models\RestaurantUser;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\CommissionCalculationService;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\RestaurantEarningsService;
use App\Services\Finance\SettlementCalculationService;
use App\Services\Restaurant\RestaurantAccess;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * A restaurant's own finances (Module 36): what it earned, what it is owed, its settlement statements and the state
 * of its payouts. Read-only — nothing here can change an amount, a commission, a settlement or a payout.
 *
 * WHOSE DATA: only locations the signed-in person may open (their active memberships) AND holds
 * restaurant.finance.view for. Another restaurant's settlement, payout or commercial terms do not exist for them
 * (404, like an id that was never issued); a location of their own organization outside their membership neither.
 * With ?location= one location is read; without it, every location they may read — so an owner sees the whole
 * organization and a manager of one location only that one. Amounts are per currency and never added across them.
 *
 * WHAT IS NOT HERE: a customer's name, phone, e-mail or payment details (a line names an order number), another
 * restaurant, a provider reference, the platform's own revenue or costs, and anything called an invoice.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly RestaurantEarningsService $earnings,
        private readonly SettlementCalculationService $settlements,
        private readonly CommissionCalculationService $commission,
        private readonly FinancePresenter $presenter,
        private readonly AuditRecorder $audit,
        private readonly FinanceMetrics $metrics,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $locations = $this->locations($request);
        $timezone = $this->timezone($locations);
        $ids = $locations->modelKeys();

        return response()->json([
            'scope' => $this->scope($locations),
            'timezone' => $timezone,
            'currencies' => array_map(fn (array $row): array => self::moneyRow($row), $this->earnings->summary($ids, $timezone)),
            'series' => array_map(fn (array $row): array => self::moneyRow($row), $this->earnings->series($ids, $timezone, 30)),
            'definitions' => RestaurantEarningsService::DEFINITIONS,
            'settlement_policy' => [
                'cadence' => (string) config('finance.settlements.cadence'),
                'earnings_available_when' => (string) config('finance.recognition.trigger'),
                'approval' => 'PENDING BUSINESS APPROVAL',
                'note' => 'Settlement timing and payout schedule are not final.',
            ],
            'payouts' => ['live' => false, 'note' => 'Live payouts to restaurants are not enabled yet.'],
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location' => ['sometimes', 'string', 'uuid'],
            'state' => ['sometimes', 'string', Rule::in(['AVAILABLE', 'HELD', 'IN_SETTLEMENT', 'SETTLED', 'PAID'])],
            'type' => ['sometimes', 'string', Rule::in([RestaurantPayableItem::TYPE_ORDER_EARNING, RestaurantPayableItem::TYPE_REFUND_ADJUSTMENT, RestaurantPayableItem::TYPE_DISPUTE_ADJUSTMENT, RestaurantPayableItem::TYPE_MANUAL_ADJUSTMENT])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('finance.reports.max_page_size')],
        ]);
        $locations = $this->locations($request);
        $timezone = $this->timezone($locations);
        $query = $this->items($locations, $timezone, $input)->with('settlement')->orderByDesc('available_at')->orderByDesc('id');
        $page = $query->paginate((int) ($input['per_page'] ?? config('finance.reports.page_size')));
        $held = $this->heldSet($page->getCollection());

        return JsonResource::collection($page->through(fn (RestaurantPayableItem $i): array => $this->presenter->earning($i, FinancePresenter::earningState($i, isset($held[$i->getKey()])))))->response();
    }

    public function exportEarnings(Request $request): Response
    {
        $input = $request->validate([
            'location' => ['sometimes', 'string', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $locations = $this->locations($request);
        $timezone = $this->timezone($locations);
        $max = (int) config('finance.reports.export_max_range_days');
        if (CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) > $max) {
            throw new ApiException(422, 'range_too_long', 'An export covers at most '.$max.' days.', ['fields' => ['to' => ['At most '.$max.' days after "from".']]]);
        }
        $limit = (int) config('finance.reports.export_max_rows');
        $items = $this->items($locations, $timezone, $input)->with('settlement')->orderBy('available_at')->orderBy('id')->limit($limit + 1)->get();
        if ($items->count() > $limit) {
            throw new ApiException(422, 'export_too_large', 'This period has more than '.$limit.' lines. Choose a shorter period.');
        }
        $held = $this->heldSet($items);
        $names = $locations->pluck('name', 'id');
        $rows = $items->map(fn (RestaurantPayableItem $i): array => [
            CarbonImmutable::instance($i->available_at)->setTimezone($timezone)->format('Y-m-d H:i'), (string) ($names[$i->location_id] ?? ''), $i->type, $i->order_number, $i->currency,
            Csv::amount((int) $i->gross_minor, (string) $i->currency), Csv::amount((int) $i->restaurant_discount_minor, (string) $i->currency), Csv::amount((int) $i->commission_minor, (string) $i->currency),
            Csv::amount((int) $i->amount_minor, (string) $i->currency), FinancePresenter::earningState($i, isset($held[$i->getKey()])), $i->settlement?->reference,
        ]);
        $this->exported($request, $locations, 'earnings', ['from' => $input['from'], 'to' => $input['to'], 'rows' => $rows->count()]);

        return Csv::response('earnings-'.$input['from'].'-to-'.$input['to'].'.csv',
            ['Date ('.$timezone.')', 'Restaurant', 'Type', 'Order number', 'Currency', 'Gross sales', 'Restaurant-funded discount', 'Commission', 'Net amount', 'State', 'Settlement'], $rows);
    }

    public function settlements(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location' => ['sometimes', 'string', 'uuid'],
            // The restaurant's own words (IN_REVIEW, APPROVED, PAYMENT_PENDING, PAID, CLOSED) — never the internal states.
            'status' => ['sometimes', 'string', Rule::in(SettlementStatus::RESTAURANT_WORDS)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('finance.reports.max_page_size')],
        ]);
        $locations = $this->locations($request);
        // A withdrawn settlement was never owed: it is not part of a restaurant's history.
        $query = RestaurantSettlement::query()->with('location')->whereIn('location_id', $locations->modelKeys())->where('status', '<>', SettlementStatus::Cancelled->value)
            ->when(isset($input['status']), fn (Builder $q) => $q->whereIn('status', SettlementStatus::behind((string) $input['status'])))->orderByDesc('period_end')->orderByDesc('id');

        return JsonResource::collection($query->paginate((int) ($input['per_page'] ?? config('finance.reports.page_size')))->through(fn (RestaurantSettlement $s): array => $this->presenter->settlement($s)))->response();
    }

    public function settlement(Request $request, string $settlement): JsonResponse
    {
        $found = $this->findSettlement($request, $settlement);
        $lines = $found->lines()->get();

        return response()->json(['settlement' => [
            ...$this->presenter->settlement($found),
            'lines' => $lines->map(fn ($l): array => $this->presenter->settlementLine($l))->all(),
            'payouts' => $found->payouts()->with(['settlement', 'location'])->where('status', '<>', PayoutStatus::Cancelled->value)->get()->map(fn (RestaurantPayout $p): array => $this->presenter->payout($p))->all(),
        ]]);
    }

    public function exportSettlement(Request $request, string $settlement): Response
    {
        $found = $this->findSettlement($request, $settlement);
        $c = (string) $found->currency;
        $zone = (string) $found->timezone;
        $rows = collect([
            ['Settlement statement — this is not a tax invoice'],
            ['Reference', $found->reference], ['Restaurant', $found->restaurant_name], ['Currency', $c], ['Status', $found->status->value],
            ['Period start ('.$zone.')', CarbonImmutable::instance($found->period_start)->setTimezone($zone)->format('Y-m-d H:i')],
            ['Period end ('.$zone.')', CarbonImmutable::instance($found->period_end)->setTimezone($zone)->format('Y-m-d H:i')],
            ['Completed orders', (int) $found->order_count], ['Gross sales', Csv::amount((int) $found->gross_sales_minor, $c)], ['Restaurant-funded discounts', Csv::amount((int) $found->restaurant_discount_minor, $c)],
            ['Commission', Csv::amount((int) $found->commission_minor, $c)], ['Refund adjustments', Csv::amount((int) $found->refund_adjustments_minor, $c)], ['Other adjustments', Csv::amount((int) $found->other_adjustments_minor, $c)],
            ['Net payable', Csv::amount((int) $found->net_payable_minor, $c)], [],
            ['Line', 'Date ('.$zone.')', 'Type', 'Order number', 'Gross sales', 'Restaurant-funded discount', 'Commission', 'Net amount'],
        ])->concat($found->lines()->get()->map(fn ($l): array => [
            (int) $l->line_no, CarbonImmutable::instance($l->occurred_at)->setTimezone($zone)->format('Y-m-d H:i'), $l->type, $l->order_number,
            Csv::amount((int) $l->gross_minor, $c), Csv::amount((int) $l->restaurant_discount_minor, $c), Csv::amount((int) $l->commission_minor, $c), Csv::amount((int) $l->amount_minor, $c),
        ]));
        $this->audit->record('finance.exported', $found, $this->user($request), ['export' => ['from' => null, 'to' => 'settlement_statement']], null, $found->market_id);
        $this->metrics->record('export');

        return Csv::response('settlement-'.$found->reference.'.csv', ['FoodOnTheGo'], $rows);
    }

    public function payouts(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location' => ['sometimes', 'string', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('finance.reports.max_page_size')],
        ]);
        $locations = $this->locations($request);
        $query = RestaurantPayout::query()->with(['settlement', 'location'])->whereIn('location_id', $locations->modelKeys())->where('status', '<>', PayoutStatus::Cancelled->value)->orderByDesc('requested_at')->orderByDesc('id');
        $response = JsonResource::collection($query->paginate((int) ($input['per_page'] ?? config('finance.reports.page_size')))->through(fn (RestaurantPayout $p): array => $this->presenter->payout($p)))->response()->getData(true);
        // Where payouts go is the owner's to see (restaurant.payout_account.view) — and it is only ever a mask.
        $response['payout_accounts'] = $locations->filter(fn (RestaurantLocation $l): bool => in_array(Permission::RestaurantPayoutAccountView->value, $this->access->permissions($this->user($request), $l), true))
            ->map(fn (RestaurantLocation $l): array => ['location' => ['id' => (string) $l->public_id, 'name' => (string) $l->name], ...$this->presenter->payoutProfile(RestaurantPayoutProfile::query()->where('location_id', $l->getKey())->orderByDesc('id')->first())])->values()->all();
        $response['live_payouts'] = false;

        return response()->json($response);
    }

    /**
     * The commercial terms that apply to the person's own locations right now.
     */
    public function terms(Request $request): JsonResponse
    {
        $locations = $this->locations($request);
        $visible = (bool) config('finance.restaurant.terms_visible');

        return response()->json([
            'visible' => $visible,
            'terms' => $locations->map(function (RestaurantLocation $l) use ($visible): array {
                $plan = ! $visible || $l->market_id === null ? null : $this->commission->planFor((int) $l->market_id, (int) $l->organization_id, (int) $l->getKey(), now());

                return ['location' => ['id' => (string) $l->public_id, 'name' => (string) $l->name], 'currency' => (string) $l->currency, 'commission' => $plan === null ? null : $this->presenter->planForRestaurant($plan)];
            })->values()->all(),
            'provider_fees' => ['borne_by' => 'PLATFORM', 'approval' => (string) config('finance.provider_fees.approval')],
            'approval' => 'PENDING BUSINESS APPROVAL',
        ]);
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * The locations this request reads: the one named, or every location the person may read finances of.
     *
     * @return Collection<int, RestaurantLocation>
     */
    private function locations(Request $request): Collection
    {
        $user = $this->user($request);
        $named = $request->query('location');
        if (is_string($named) && $named !== '') {
            if (! Str::isUuid($named)) {
                throw new ApiException(422, 'validation_failed', 'The location must be an id.', ['fields' => ['location' => ['Must be a valid id.']]]);
            }
            $location = $this->access->locations($user)->with('organization')->where('restaurant_locations.public_id', $named)->first()
                ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
            $this->access->authorize($user, $location, Permission::RestaurantFinanceView);

            return new \Illuminate\Database\Eloquent\Collection([$location]);
        }
        $all = $this->access->locations($user)->with('organization')->orderBy('restaurant_locations.name')->get()
            ->filter(fn (RestaurantLocation $l): bool => in_array(Permission::RestaurantFinanceView->value, $this->access->permissions($user, $l), true))->values();
        if ($all->isEmpty()) {
            throw new AuthorizationException;
        }

        return $all;
    }

    /**
     * @param  Collection<int, RestaurantLocation>  $locations
     */
    private function timezone(Collection $locations): string
    {
        return $this->settlements->timezoneOf($locations->first());
    }

    /**
     * @param  Collection<int, RestaurantLocation>  $locations
     * @return array<string, mixed>
     */
    private function scope(Collection $locations): array
    {
        return ['locations' => $locations->map(fn (RestaurantLocation $l): array => ['id' => (string) $l->public_id, 'name' => (string) $l->name, 'currency' => (string) $l->currency])->values()->all(), 'all_readable' => ! request()->filled('location')];
    }

    /**
     * @param  Collection<int, RestaurantLocation>  $locations
     * @param  array<string, mixed>  $input
     * @return Builder<RestaurantPayableItem>
     */
    private function items(Collection $locations, string $timezone, array $input): Builder
    {
        $query = RestaurantPayableItem::query()->whereIn('restaurant_payable_items.location_id', $locations->modelKeys());
        if (isset($input['type'])) {
            $query->where('type', $input['type']);
        }
        if (isset($input['from'])) {
            $query->where('available_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['from'], $timezone)->utc());
        }
        if (isset($input['to'])) {
            $query->where('available_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['to'], $timezone)->addDay()->utc());
        }
        match ($input['state'] ?? null) {
            'AVAILABLE' => $query->settleable(),
            'HELD' => $query->held(),
            'IN_SETTLEMENT' => $query->where('restaurant_payable_items.status', RestaurantPayableItem::STATUS_SETTLED)->whereHas('settlement', fn (Builder $s) => $s->whereNotIn('status', [SettlementStatus::Paid->value, SettlementStatus::Closed->value])),
            // Lines of a statement that added up to zero: settled, with nothing to pay.
            'SETTLED' => $query->where('restaurant_payable_items.status', RestaurantPayableItem::STATUS_SETTLED)->whereHas('settlement', fn (Builder $s) => $s->where('status', SettlementStatus::Closed->value)),
            'PAID' => $query->where('restaurant_payable_items.status', RestaurantPayableItem::STATUS_SETTLED)->whereHas('settlement', fn (Builder $s) => $s->where('status', SettlementStatus::Paid->value)),
            default => null,
        };

        return $query;
    }

    /**
     * Which of these lines are held — by a hold of their own or of their location. Two queries for the whole page.
     *
     * @param  Collection<int, RestaurantPayableItem>  $items
     * @return array<int, true>
     */
    private function heldSet(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $active = FinancialHold::query()->where('status', FinancialHold::STATUS_ACTIVE);
        $byItem = (clone $active)->whereIn('payable_item_id', $items->modelKeys())->pluck('payable_item_id')->flip();
        $byLocation = (clone $active)->where('scope', FinancialHold::SCOPE_LOCATION)->whereIn('location_id', $items->pluck('location_id')->unique()->all())->pluck('location_id')->flip();
        $held = [];
        foreach ($items as $item) {
            if (isset($byItem[$item->getKey()]) || isset($byLocation[$item->location_id])) {
                $held[(int) $item->getKey()] = true;
            }
        }

        return $held;
    }

    private function findSettlement(Request $request, string $publicId): RestaurantSettlement
    {
        $found = RestaurantSettlement::query()->with('location.organization')->where('public_id', $publicId)->where('status', '<>', SettlementStatus::Cancelled->value)->first();
        // Another restaurant's settlement is not "forbidden": for this person it does not exist.
        if ($found === null || $found->location === null || ! $this->access->reaches($this->user($request), $found->location)) {
            throw ApiException::notFound('settlement_not_found', 'This settlement does not exist.');
        }
        $this->access->authorize($this->user($request), $found->location, Permission::RestaurantFinanceView);

        return $found;
    }

    /**
     * @param  Collection<int, RestaurantLocation>  $locations
     * @param  array<string, mixed>  $facts
     */
    private function exported(Request $request, Collection $locations, string $what, array $facts): void
    {
        $target = $locations->count() === 1 ? $locations->first() : $locations->first()->organization;
        $this->audit->record('finance.exported', $target, $this->user($request), ['export' => ['from' => null, 'to' => $what], 'facts' => ['from' => null, 'to' => $facts], 'locations' => ['from' => null, 'to' => $locations->count()]], null, $locations->first()->market_id);
        $this->metrics->record('export');
    }

    /**
     * Every "*_minor" figure of a row becomes a money object of the row's currency.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function moneyRow(array $row): array
    {
        $currency = (string) $row['currency'];
        $out = [];
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $out[$key] = array_is_list($value) ? $value : self::moneyRow([...$value, 'currency' => $currency]);
                unset($out[$key]['currency']);
            } elseif (is_string($key) && str_ends_with($key, '_minor')) {
                $out[substr($key, 0, -6)] = FinancePresenter::money((int) $value, $currency);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function user(Request $request): RestaurantUser
    {
        return $request->user();
    }
}
