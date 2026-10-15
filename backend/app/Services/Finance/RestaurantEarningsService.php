<?php

namespace App\Services\Finance;

use App\Enums\AllocationStatus;
use App\Enums\LedgerAccountCode;
use App\Enums\PayoutStatus;
use App\Enums\SettlementStatus;
use App\Models\FinancialHold;
use App\Models\RestaurantPayableItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What restaurants earned, are owed and were paid (Module 36) — read from the payable lines and the ledger with SQL
 * sums. Nothing is cached and no order is loaded into memory to be added up.
 *
 * WHAT EACH FIGURE MEANS (the same words are sent with the figures, so a screen cannot label them differently):
 *
 *   gross sales     the merchandise of COMPLETED orders as it was priced, before any discount, tax or fee
 *   commission      the platform's commission on those orders, by the terms that applied when each was placed
 *   adjustments     refunds the restaurant bears, dispute and manual adjustments (signed)
 *   net earnings    gross sales − the discounts the restaurant paid for − commission (+ tax / fees passed to it) + adjustments
 *   pending         the net of orders that are placed and not completed: NOT earned yet, never settled
 *   available       earned, not held, not in a settlement yet
 *   held            earned and kept out of settlement by an explicit hold
 *   in settlement   in a settlement statement that is not paid yet
 *   paid out        payouts that were CONFIRMED — never a payout that is only on its way
 *   balance         what the platform owes right now, from the ledger (available + held + in settlement)
 *
 * Amounts are per CURRENCY: two currencies are two rows, never one sum.
 */
final class RestaurantEarningsService
{
    /**
     * A payable line is held when an ACTIVE hold names the line or covers its whole location. Written as two
     * UNCORRELATED IN lists (Module 40) rather than one correlated EXISTS with an OR: PostgreSQL computes each list once
     * and hashes it, instead of running a subquery per line (88 ms → 8 ms for 18,780 lines of one location in the
     * medium perf dataset). NULLs are left out of both lists so the test is strictly true / false; parenthesised
     * because it is negated as a whole.
     */
    private const HELD = "(i.id in (select h.payable_item_id from financial_holds h where h.status = '".FinancialHold::STATUS_ACTIVE."' and h.payable_item_id is not null)"
        ." or i.location_id in (select h.location_id from financial_holds h where h.status = '".FinancialHold::STATUS_ACTIVE."' and h.scope = '".FinancialHold::SCOPE_LOCATION."' and h.location_id is not null))";

    public const DEFINITIONS = [
        'gross_sales' => 'Merchandise of completed orders as priced, before discounts, tax and fees.',
        'commission' => 'FoodOnTheGo commission on those orders, by the terms that applied when each order was placed.',
        'adjustments' => 'Refunds borne by the restaurant, dispute adjustments and manual adjustments.',
        'net_earnings' => 'Gross sales minus restaurant-funded discounts and commission, plus tax or fees passed to the restaurant, plus adjustments.',
        'pending' => 'Estimated net of orders that are placed and not completed yet. Not earned until completion.',
        'available' => 'Earned and ready for the next settlement.',
        'held' => 'Earned and kept out of settlement by a financial hold.',
        'in_settlement' => 'Included in a settlement statement that is not paid yet.',
        'paid_out' => 'Confirmed payouts only.',
        'balance' => 'What FoodOnTheGo owes the restaurant now, from the ledger.',
    ];

    public function __construct(private readonly LedgerPostingService $ledger) {}

    /**
     * @param  list<int>  $locationIds
     * @return list<array<string, mixed>> one row per currency
     */
    public function summary(array $locationIds, string $timezone): array
    {
        if ($locationIds === []) {
            return [];
        }
        $now = CarbonImmutable::now($timezone);
        $periods = ['today' => $now->startOfDay(), 'this_week' => $now->startOfWeek((int) config('finance.settlements.week_starts_on', 1)), 'this_month' => $now->startOfMonth()];
        $by = [];
        $row = function (string $currency) use (&$by, $periods): void {
            $by[$currency] ??= [
                'currency' => $currency, 'pending_minor' => 0, 'pending_orders' => 0, 'awaiting_terms_orders' => 0, 'available_minor' => 0, 'held_minor' => 0, 'in_settlement_minor' => 0,
                'paid_out_minor' => 0, 'balance_minor' => 0, 'lifetime' => self::emptyPeriod(),
                'periods' => array_map(fn () => self::emptyPeriod(), $periods),
            ];
        };

        foreach (DB::table('order_financial_allocations')->whereIn('location_id', $locationIds)->whereIn('status', [AllocationStatus::Pending->value, AllocationStatus::ReviewRequired->value])
            ->selectRaw('currency, status, count(*) as n, coalesce(sum(restaurant_net_minor), 0) as net')->groupBy('currency', 'status')->get() as $r) {
            $row((string) $r->currency);
            if ($r->status === AllocationStatus::Pending->value) {
                $by[$r->currency]['pending_minor'] = (int) $r->net;
                $by[$r->currency]['pending_orders'] = (int) $r->n;
            } else {
                $by[$r->currency]['awaiting_terms_orders'] = (int) $r->n;
            }
        }

        $held = self::HELD;
        $open = "'".implode("','", [SettlementStatus::Calculated->value, SettlementStatus::ReviewRequired->value, SettlementStatus::Approved->value, SettlementStatus::PaymentPending->value, SettlementStatus::Failed->value])."'";
        $states = DB::table('restaurant_payable_items as i')->leftJoin('restaurant_settlements as s', 's.id', '=', 'i.settlement_id')->whereIn('i.location_id', $locationIds)
            ->selectRaw("i.currency,
                coalesce(sum(i.amount_minor) filter (where i.status = 'AVAILABLE' and not {$held}), 0) as available,
                coalesce(sum(i.amount_minor) filter (where i.status = 'AVAILABLE' and {$held}), 0) as held,
                coalesce(sum(i.amount_minor) filter (where i.status = 'SETTLED' and s.status in ({$open})), 0) as in_settlement")
            ->groupBy('i.currency')->get();
        foreach ($states as $r) {
            $row((string) $r->currency);
            $by[$r->currency]['available_minor'] = (int) $r->available;
            $by[$r->currency]['held_minor'] = (int) $r->held;
            $by[$r->currency]['in_settlement_minor'] = (int) $r->in_settlement;
        }

        foreach (DB::table('restaurant_payouts')->whereIn('location_id', $locationIds)->where('status', PayoutStatus::Paid->value)->selectRaw('currency, coalesce(sum(amount_minor), 0) as paid')->groupBy('currency')->get() as $r) {
            $row((string) $r->currency);
            $by[$r->currency]['paid_out_minor'] = (int) $r->paid;
        }

        foreach ($this->balances($locationIds) as $currency => $balance) {
            $row($currency);
            $by[$currency]['balance_minor'] = array_sum($balance);
        }

        $select = 'i.currency, '.self::periodColumns();
        foreach (DB::table('restaurant_payable_items as i')->whereIn('i.location_id', $locationIds)->selectRaw($select)->groupBy('i.currency')->get() as $r) {
            $row((string) $r->currency);
            $by[$r->currency]['lifetime'] = self::period($r);
        }
        foreach ($periods as $name => $start) {
            foreach (DB::table('restaurant_payable_items as i')->whereIn('i.location_id', $locationIds)->where('i.available_at', '>=', $start->utc())->selectRaw($select)->groupBy('i.currency')->get() as $r) {
                $row((string) $r->currency);
                $by[$r->currency]['periods'][$name] = self::period($r);
            }
        }
        ksort($by);

        return array_values($by);
    }

    /**
     * Earnings per day of the reporting time zone, for a small chart.
     *
     * @param  list<int>  $locationIds
     * @return list<array<string, mixed>>
     */
    public function series(array $locationIds, string $timezone, int $days = 30): array
    {
        if ($locationIds === []) {
            return [];
        }
        $from = CarbonImmutable::now($timezone)->startOfDay()->subDays(max(1, min(92, $days)) - 1);

        return DB::table('restaurant_payable_items as i')->whereIn('i.location_id', $locationIds)->where('i.available_at', '>=', $from->utc())
            ->selectRaw('(i.available_at at time zone '.FinanceReportService::zone($timezone).')::date as day, '.self::periodColumns().', i.currency')
            ->groupByRaw('(i.available_at at time zone '.FinanceReportService::zone($timezone).')::date, i.currency')->orderBy('day')->get()
            ->map(fn ($r): array => ['day' => (string) $r->day, 'currency' => (string) $r->currency, ...self::period($r)])->all();
    }

    /**
     * The ledger's payable balance per currency and location.
     *
     * @param  list<int>  $locationIds
     * @return array<string, array<int, int>> currency => [location id => balance]
     */
    public function balances(array $locationIds): array
    {
        $balances = [];
        $rows = DB::table('ledger_accounts as a')->leftJoin('ledger_entries as e', 'e.account_id', '=', 'a.id')->where('a.code', LedgerAccountCode::RestaurantPayable->value)->whereIn('a.location_id', $locationIds)
            ->selectRaw("a.currency, a.location_id, coalesce(sum(case e.direction when 'C' then e.amount_minor when 'D' then -e.amount_minor else 0 end), 0) as balance")->groupBy('a.currency', 'a.location_id')->get();
        foreach ($rows as $r) {
            $balances[(string) $r->currency][(int) $r->location_id] = (int) $r->balance;
        }

        return $balances;
    }

    /**
     * Where each (location, currency) of a page stands — three grouped queries for the whole page.
     *
     * @param  list<int>  $locationIds
     * @return array<string, array<string, int>> "location:currency" => figures
     */
    public function standing(array $locationIds): array
    {
        $out = [];
        if ($locationIds === []) {
            return $out;
        }
        $held = self::HELD;
        $rows = DB::table('restaurant_payable_items as i')->leftJoin('restaurant_settlements as s', 's.id', '=', 'i.settlement_id')->whereIn('i.location_id', $locationIds)
            ->selectRaw("i.location_id, i.currency,
                coalesce(sum(i.amount_minor) filter (where i.status = 'AVAILABLE' and not {$held}), 0) as available,
                coalesce(sum(i.amount_minor) filter (where i.status = 'AVAILABLE' and {$held}), 0) as held,
                coalesce(sum(i.amount_minor) filter (where i.status = 'SETTLED' and s.status <> 'PAID' and s.status <> 'CLOSED'), 0) as in_settlement,
                count(*) filter (where i.status = 'AVAILABLE') as open_lines")
            ->groupBy('i.location_id', 'i.currency')->get();
        foreach ($rows as $r) {
            $out[$r->location_id.':'.$r->currency] = ['available_minor' => (int) $r->available, 'held_minor' => (int) $r->held, 'in_settlement_minor' => (int) $r->in_settlement, 'open_lines' => (int) $r->open_lines, 'paid_out_minor' => 0];
        }
        foreach (DB::table('restaurant_payouts')->whereIn('location_id', $locationIds)->where('status', PayoutStatus::Paid->value)->selectRaw('location_id, currency, sum(amount_minor) as paid')->groupBy('location_id', 'currency')->get() as $r) {
            $out[$r->location_id.':'.$r->currency] ??= ['available_minor' => 0, 'held_minor' => 0, 'in_settlement_minor' => 0, 'open_lines' => 0, 'paid_out_minor' => 0];
            $out[$r->location_id.':'.$r->currency]['paid_out_minor'] = (int) $r->paid;
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private static function emptyPeriod(): array
    {
        return ['orders' => 0, 'gross_sales_minor' => 0, 'restaurant_discount_minor' => 0, 'commission_minor' => 0, 'adjustments_minor' => 0, 'net_earnings_minor' => 0];
    }

    private static function periodColumns(): string
    {
        return "count(*) filter (where i.type = '".RestaurantPayableItem::TYPE_ORDER_EARNING."') as orders, coalesce(sum(i.gross_minor), 0) as gross, coalesce(sum(i.restaurant_discount_minor), 0) as discount,
            coalesce(sum(i.commission_minor), 0) as commission, coalesce(sum(i.amount_minor) filter (where i.type <> '".RestaurantPayableItem::TYPE_ORDER_EARNING."'), 0) as adjustments, coalesce(sum(i.amount_minor), 0) as net";
    }

    /**
     * @return array<string, int>
     */
    private static function period(object $r): array
    {
        return ['orders' => (int) $r->orders, 'gross_sales_minor' => (int) $r->gross, 'restaurant_discount_minor' => (int) $r->discount, 'commission_minor' => (int) $r->commission, 'adjustments_minor' => (int) $r->adjustments, 'net_earnings_minor' => (int) $r->net];
    }
}
