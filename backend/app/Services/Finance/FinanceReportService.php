<?php

namespace App\Services\Finance;

use App\Enums\LedgerAccountCode;
use App\Models\FinancialAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finance reporting for administrators (Module 36) — SQL aggregation over the ledger and the allocations, per
 * currency, for an explicit period and market scope. No report loads ledger rows into memory.
 *
 * Words are used deliberately:
 *   GROSS MARKETPLACE SALES is the merchandise restaurants sold through the platform. It is NOT FoodOnTheGo's revenue.
 *   PLATFORM REVENUE is the commission and the customer fees that are the platform's — nothing else.
 *   PROVIDER FEES are the amounts the payment provider itself reported. None is estimated: when no provider record
 *   was imported, the figure is zero and says so.
 */
final class FinanceReportService
{
    /**
     * @param  list<int>|null  $marketIds  null = every market
     * @return array<string, mixed>
     */
    public function overview(?array $marketIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $scoped = fn (Builder $q, string $column) => $q->when($marketIds !== null, fn (Builder $w) => $w->whereIn($column, $marketIds));
        $by = [];
        $row = function (string $currency) use (&$by): void {
            $by[$currency] ??= ['currency' => $currency, 'period' => array_fill_keys([
                'customer_payments_minor', 'refunds_minor', 'gross_marketplace_sales_minor', 'platform_commission_minor', 'platform_fee_revenue_minor', 'promotion_expense_minor',
                'refund_expense_minor', 'restaurant_earnings_minor', 'provider_fees_minor', 'provider_fee_tax_minor', 'provider_settlements_received_minor', 'restaurant_payouts_minor', 'orders_recognized',
            ], 0), 'standing' => array_fill_keys(['restaurant_payables_minor', 'payment_clearing_minor', 'provider_clearing_minor', 'refund_clearing_minor', 'dispute_clearing_minor', 'tax_held_minor'], 0)];
        };

        // The period, by account: what moved.
        $moved = $scoped(DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')->where('e.occurred_at', '>=', $from)->where('e.occurred_at', '<', $to), 'e.market_id')
            ->selectRaw("a.code, e.currency, coalesce(sum(case e.direction when 'D' then e.amount_minor else 0 end), 0) as debits, coalesce(sum(case e.direction when 'C' then e.amount_minor else 0 end), 0) as credits")
            ->groupBy('a.code', 'e.currency')->get();
        foreach ($moved as $r) {
            $row((string) $r->currency);
            $p = &$by[$r->currency]['period'];
            $net = fn (string $side): int => $side === 'C' ? (int) $r->credits - (int) $r->debits : (int) $r->debits - (int) $r->credits;
            match ($r->code) {
                LedgerAccountCode::PlatformCommissionRevenue->value => $p['platform_commission_minor'] = $net('C'),
                LedgerAccountCode::PlatformFeeRevenue->value => $p['platform_fee_revenue_minor'] = $net('C'),
                LedgerAccountCode::PromotionExpense->value => $p['promotion_expense_minor'] = $net('D'),
                LedgerAccountCode::PlatformRefundExpense->value => $p['refund_expense_minor'] = $net('D'),
                LedgerAccountCode::ProviderFeeExpense->value => $p['provider_fees_minor'] = $net('D'),
                LedgerAccountCode::ProviderTaxExpense->value => $p['provider_fee_tax_minor'] = $net('D'),
                LedgerAccountCode::PlatformBank->value => $p['provider_settlements_received_minor'] = $net('D'),
                LedgerAccountCode::PayoutClearing->value => $p['restaurant_payouts_minor'] = $net('C'),
                default => null,
            };
            unset($p);
        }
        $events = $scoped(DB::table('ledger_transactions')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->whereIn('type', ['PAYMENT_VERIFIED', 'REFUND_COMPLETED']), 'market_id')
            ->selectRaw('type, currency, coalesce(sum(amount_minor), 0) as amount')->groupBy('type', 'currency')->get();
        foreach ($events as $r) {
            $row((string) $r->currency);
            $by[$r->currency]['period'][$r->type === 'PAYMENT_VERIFIED' ? 'customer_payments_minor' : 'refunds_minor'] = (int) $r->amount;
        }
        $sales = $scoped(DB::table('order_financial_allocations')->where('status', 'RECOGNIZED')->where('recognized_at', '>=', $from)->where('recognized_at', '<', $to), 'market_id')
            ->selectRaw('currency, count(*) as n, coalesce(sum(merchandise_gross_minor), 0) as gross, coalesce(sum(restaurant_net_minor), 0) as net')->groupBy('currency')->get();
        foreach ($sales as $r) {
            $row((string) $r->currency);
            $by[$r->currency]['period']['gross_marketplace_sales_minor'] = (int) $r->gross;
            $by[$r->currency]['period']['restaurant_earnings_minor'] = (int) $r->net;
            $by[$r->currency]['period']['orders_recognized'] = (int) $r->n;
        }

        // Now, by account: what is held and owed.
        $standing = $scoped(DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')->whereIn('a.code', [
            LedgerAccountCode::RestaurantPayable->value, LedgerAccountCode::PaymentClearing->value, LedgerAccountCode::ProviderClearing->value, LedgerAccountCode::RefundClearing->value,
            LedgerAccountCode::DisputeClearing->value, LedgerAccountCode::TaxLiability->value,
        ]), 'e.market_id')->selectRaw("a.code, a.normal_side, e.currency, coalesce(sum(case e.direction when 'D' then e.amount_minor else -e.amount_minor end), 0) as debit_balance")->groupBy('a.code', 'a.normal_side', 'e.currency')->get();
        foreach ($standing as $r) {
            $row((string) $r->currency);
            $balance = $r->normal_side === 'D' ? (int) $r->debit_balance : -(int) $r->debit_balance;
            $by[$r->currency]['standing'][match ($r->code) {
                LedgerAccountCode::RestaurantPayable->value => 'restaurant_payables_minor',
                LedgerAccountCode::PaymentClearing->value => 'payment_clearing_minor',
                LedgerAccountCode::ProviderClearing->value => 'provider_clearing_minor',
                LedgerAccountCode::RefundClearing->value => 'refund_clearing_minor',
                LedgerAccountCode::DisputeClearing->value => 'dispute_clearing_minor',
                default => 'tax_held_minor',
            }] = $balance;
        }
        foreach ($by as $currency => $figures) {
            // Revenue is the commission and the platform's fees. Gross marketplace sales are never added to it.
            $by[$currency]['period']['platform_revenue_minor'] = $figures['period']['platform_commission_minor'] + $figures['period']['platform_fee_revenue_minor'];
        }
        ksort($by);

        // A map of counts is a JSON object also when it is empty.
        $count = fn (string $table, string $column = 'status') => (object) $scoped(DB::table($table), 'market_id')->selectRaw("{$column} as k, count(*) as n")->groupBy($column)->pluck('n', 'k')->map(fn ($n): int => (int) $n)->all();
        $differences = $scoped(DB::table('reconciliation_differences')->whereIn('status', ['OPEN', 'INVESTIGATING']), 'market_id')->selectRaw('severity as k, count(*) as n')->groupBy('severity')->pluck('n', 'k')->map(fn ($n): int => (int) $n)->all();

        return [
            'currencies' => array_values($by),
            'queues' => [
                'settlements' => $count('restaurant_settlements'),
                'payouts' => $count('restaurant_payouts'),
                'differences_open' => ['total' => array_sum($differences), 'by_severity' => (object) $differences],
                'allocations_awaiting_terms' => (int) $scoped(DB::table('order_financial_allocations')->where('status', 'REVIEW_REQUIRED'), 'market_id')->count(),
                'liability_decisions_pending' => (int) $scoped(DB::table('financial_adjustments')->where('status', FinancialAdjustment::STATUS_PENDING_DECISION), 'market_id')->count(),
                'adjustments_awaiting_approval' => (int) $scoped(DB::table('financial_adjustments')->where('status', FinancialAdjustment::STATUS_PENDING_APPROVAL), 'market_id')->count(),
                'holds_active' => (int) $scoped(DB::table('financial_holds')->where('status', 'ACTIVE'), 'market_id')->count(),
            ],
            'provider_fees' => [
                'source' => 'PROVIDER_REPORTED',
                'estimated' => false,
                'records_imported' => (int) DB::table('provider_settlement_items')->count(),
                'note' => 'Provider fees are posted only from the provider\'s own settlement records. Without an import the figure is zero — it is not an estimate.',
            ],
        ];
    }

    /**
     * A time zone name as an SQL literal. A grouped expression must be the SAME text in the select list and in GROUP BY
     * (a bound parameter is a different one each time it appears), so the name is written into the statement — after
     * it was checked to be a zone name PHP knows, which no request can make up.
     */
    public static function zone(string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(), true) && preg_match('/\A[A-Za-z0-9_\/+-]+\z/', $timezone) === 1 ? "'".$timezone."'" : "'UTC'";
    }

    /**
     * Commission of recognized orders, grouped.
     *
     * @param  'day'|'location'|'organization'|'market'|'plan'  $groupBy
     * @param  list<int>|null  $marketIds
     * @return list<array<string, mixed>>
     */
    public function commission(?array $marketIds, CarbonImmutable $from, CarbonImmutable $to, string $groupBy, string $timezone, ?int $locationId = null): array
    {
        $query = DB::table('order_financial_allocations as a')->where('a.status', 'RECOGNIZED')->where('a.recognized_at', '>=', $from)->where('a.recognized_at', '<', $to)
            ->when($marketIds !== null, fn (Builder $q) => $q->whereIn('a.market_id', $marketIds))->when($locationId !== null, fn (Builder $q) => $q->where('a.location_id', $locationId));
        $zone = self::zone($timezone);
        $sums = 'a.currency, count(*) as orders, coalesce(sum(a.merchandise_gross_minor), 0) as gross, coalesce(sum(a.commission_base_minor), 0) as base, coalesce(sum(a.commission_minor), 0) as commission, coalesce(sum(a.restaurant_net_minor), 0) as net, coalesce(sum(a.platform_discount_minor), 0) as platform_discount, coalesce(sum(a.restaurant_discount_minor), 0) as restaurant_discount';
        [$query, $label] = match ($groupBy) {
            'location' => [$query->join('restaurant_locations as l', 'l.id', '=', 'a.location_id')->selectRaw("l.public_id as key, l.name as label, {$sums}")->groupBy('l.public_id', 'l.name', 'a.currency')->orderBy('l.name'), 'location'],
            'organization' => [$query->join('restaurant_organizations as o', 'o.id', '=', 'a.organization_id')->selectRaw("o.public_id as key, o.display_name as label, {$sums}")->groupBy('o.public_id', 'o.display_name', 'a.currency')->orderBy('o.display_name'), 'organization'],
            'market' => [$query->leftJoin('markets as m', 'm.id', '=', 'a.market_id')->selectRaw("m.slug as key, m.name as label, {$sums}")->groupBy('m.slug', 'm.name', 'a.currency')->orderBy('m.name'), 'market'],
            'plan' => [$query->leftJoin('commission_plans as p', 'p.id', '=', 'a.commission_plan_id')->selectRaw("p.public_id as key, coalesce(p.name, 'Decision for one order') as label, a.commission_rate_bps as rate_bps, a.commission_base_policy as base_policy, {$sums}")
                ->groupBy('p.public_id', 'p.name', 'a.commission_rate_bps', 'a.commission_base_policy', 'a.currency')->orderBy('label'), 'plan'],
            default => [$query->selectRaw("(a.recognized_at at time zone {$zone})::date as key, (a.recognized_at at time zone {$zone})::date as label, {$sums}")->groupByRaw("(a.recognized_at at time zone {$zone})::date, a.currency")->orderBy('key'), 'day'],
        };

        return $query->limit(1000)->get()->map(fn ($r): array => [
            'group' => $label, 'key' => $r->key === null ? null : (string) $r->key, 'label' => $r->label === null ? null : (string) $r->label, 'currency' => (string) $r->currency, 'orders' => (int) $r->orders,
            'gross_marketplace_sales_minor' => (int) $r->gross, 'commission_base_minor' => (int) $r->base, 'commission_minor' => (int) $r->commission, 'restaurant_earnings_minor' => (int) $r->net,
            'platform_funded_discount_minor' => (int) $r->platform_discount, 'restaurant_funded_discount_minor' => (int) $r->restaurant_discount,
            ...(isset($r->rate_bps) ? ['rate_bps' => (int) $r->rate_bps, 'base_policy' => (string) $r->base_policy] : []),
        ])->all();
    }

    /**
     * What completed refunds did: how much went back, and who bore it — only where a liability was actually decided.
     *
     * @param  list<int>|null  $marketIds
     * @return list<array<string, mixed>>
     */
    public function refundImpact(?array $marketIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('ledger_transactions as t')->leftJoin('financial_adjustments as f', fn ($j) => $j->on('f.refund_id', '=', 't.source_id')->where('f.kind', FinancialAdjustment::KIND_REFUND))
            ->where('t.type', 'REFUND_COMPLETED')->where('t.source_type', 'refund')->where('t.occurred_at', '>=', $from)->where('t.occurred_at', '<', $to)
            ->when($marketIds !== null, fn (Builder $q) => $q->whereIn('t.market_id', $marketIds))
            ->selectRaw("t.currency, count(*) as refunds, coalesce(sum(t.amount_minor), 0) as amount,
                coalesce(sum(t.amount_minor) filter (where f.id is null), 0) as unrecognized,
                coalesce(sum(f.restaurant_share_minor) filter (where f.status = 'POSTED'), 0) as restaurant,
                coalesce(sum(f.platform_share_minor) filter (where f.status = 'POSTED'), 0) as platform,
                coalesce(sum(f.amount_minor) filter (where f.status = 'PENDING_DECISION'), 0) as undecided,
                count(*) filter (where f.status = 'PENDING_DECISION') as undecided_count")
            ->groupBy('t.currency')->orderBy('t.currency')->get();

        return $rows->map(fn ($r): array => [
            'currency' => (string) $r->currency, 'refund_count' => (int) $r->refunds, 'refund_amount_minor' => (int) $r->amount,
            // Refunds of orders that were never completed: the customer's own money went back; nobody's earning changed.
            'returned_before_recognition_minor' => (int) $r->unrecognized,
            'restaurant_funded_minor' => (int) $r->restaurant, 'platform_funded_minor' => (int) $r->platform,
            'awaiting_decision_minor' => (int) $r->undecided, 'awaiting_decision_count' => (int) $r->undecided_count,
        ])->all();
    }

    /**
     * The provider's processing costs, from its own settlement lines — per day of the report, never estimated.
     *
     * @return array{source: string, estimated: bool, rows: list<array<string, mixed>>}
     */
    public function providerCosts(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $zone = self::zone($timezone);
        $rows = DB::table('provider_settlement_items')->whereRaw('coalesce(settled_at, provider_created_at) >= ?', [$from])->whereRaw('coalesce(settled_at, provider_created_at) < ?', [$to])
            ->selectRaw("provider, currency, (coalesce(settled_at, provider_created_at) at time zone {$zone})::date as day, count(*) filter (where type = 'PAYMENT') as payments, count(*) filter (where type = 'REFUND') as refunds,
                coalesce(sum(amount_minor) filter (where type = 'PAYMENT'), 0) as paid, coalesce(sum(fee_minor), 0) as fee, coalesce(sum(tax_minor), 0) as tax, count(*) filter (where fee_transaction_id is not null) as posted")
            ->groupByRaw("provider, currency, (coalesce(settled_at, provider_created_at) at time zone {$zone})::date")->orderBy('day')->limit(1000)->get();

        return [
            'source' => 'PROVIDER_REPORTED',
            'estimated' => false,
            'rows' => $rows->map(fn ($r): array => [
                'day' => (string) $r->day, 'provider' => (string) $r->provider, 'currency' => (string) $r->currency, 'payments' => (int) $r->payments, 'refunds' => (int) $r->refunds,
                'payment_amount_minor' => (int) $r->paid, 'fee_minor' => (int) $r->fee, 'tax_on_fee_minor' => (int) $r->tax, 'fee_lines_posted' => (int) $r->posted,
            ])->all(),
        ];
    }
}
