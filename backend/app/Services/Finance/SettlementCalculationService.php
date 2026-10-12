<?php

namespace App\Services\Finance;

use App\Enums\DifferenceType;
use App\Enums\SettlementStatus;
use App\Models\AdminUser;
use App\Models\FinanceRun;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayableItem;
use App\Models\RestaurantSettlement;
use App\Models\RestaurantSettlementLine;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calculates restaurant settlements (Module 36): for one location and one currency, every payable line that became
 * available before the cutoff and is not held — each consumed exactly once.
 *
 * It reads ONLY the payable lines (which mirror the ledger): never an order, a menu, a promotion or a commission plan
 * as they are today. A settlement is a statement of what is owed; generating one sends no money and creates no payout.
 *
 * CODE INVARIANTS
 *   - a line is in one live settlement: it is locked while it is consumed and carries the settlement that took it;
 *   - one location and currency is calculated by one process at a time (an advisory lock), so two runs cannot both
 *     take the same lines, and a repeated run finds nothing left to take;
 *   - currencies are never added together: each currency of a location is its own settlement;
 *   - the totals are the sums of the lines — and the database checks the statement's own arithmetic;
 *   - a NEGATIVE total is never a settlement, a payout or a bank debit: the lines are carried forward and finance is
 *     told (FINANCE REVIEW REQUIRED); a ZERO total is a closed statement with nothing to pay.
 *
 * POLICY (config/finance.php — development defaults, PENDING BUSINESS APPROVAL): the cadence, the time zone of the
 * cutoff (the market's), the scope (per location) and the minimum payout (none).
 */
final class SettlementCalculationService
{
    public const SETTLEMENT = 'SETTLEMENT';

    public const ZERO = 'ZERO_STATEMENT';

    public const NEGATIVE = 'NEGATIVE_CARRIED_FORWARD';

    public const NOTHING = 'NOTHING_TO_SETTLE';

    public const HELD = 'LOCATION_HELD';

    public const BELOW_MINIMUM = 'BELOW_MINIMUM_PAYOUT';

    public function __construct(
        private readonly HoldService $holds,
        private readonly DifferenceService $differences,
        private readonly FinanceMetrics $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The cutoff of the last period that is over, in the market's time zone: midnight today (DAILY), last Monday
     * (WEEKLY) or the first of the month (MONTHLY).
     */
    public function cutoff(string $timezone, ?string $cadence = null, ?CarbonInterface $now = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($now ?? now())->setTimezone($timezone)->startOfDay();

        return (match ($cadence ?? (string) config('finance.settlements.cadence')) {
            'DAILY' => $local,
            'MONTHLY' => $local->startOfMonth(),
            default => $local->startOfWeek((int) config('finance.settlements.week_starts_on', 1)),
        })->utc();
    }

    public function timezoneOf(RestaurantLocation $location): string
    {
        return (string) (Market::query()->whereKey($location->market_id)->value('default_timezone') ?: $location->timezone ?: 'UTC');
    }

    /**
     * Generates (or, as a dry run, only calculates) the settlements of the given locations up to a cutoff.
     *
     * @param  Collection<int, RestaurantLocation>  $locations
     * @param  CarbonInterface|null  $until  the cutoff; null = the last finished period of each location's market
     * @return array{run: FinanceRun|null, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function generate(Collection $locations, ?CarbonInterface $until = null, bool $dryRun = false, ?AdminUser $admin = null, string $trigger = FinanceRun::BY_COMMAND): array
    {
        $started = now();
        $results = [];
        foreach ($locations as $location) {
            $timezone = $this->timezoneOf($location);
            $cutoff = $until === null ? $this->cutoff($timezone) : CarbonImmutable::instance($until)->utc();
            $currencies = RestaurantPayableItem::query()->where('location_id', $location->getKey())->where('status', RestaurantPayableItem::STATUS_AVAILABLE)->where('available_at', '<', $cutoff)->distinct()->orderBy('currency')->pluck('currency');
            foreach ($currencies as $currency) {
                $results[] = $this->one($location, (string) $currency, $cutoff, $timezone, $dryRun, $admin);
            }
        }
        $summary = ['locations' => $locations->count(), 'settlements' => 0, 'zero_statements' => 0, 'negative_carried' => 0, 'held' => 0, 'below_minimum' => 0, 'lines' => 0];
        foreach ($results as $result) {
            $counted = match ($result['outcome']) {
                self::SETTLEMENT => 'settlements', self::ZERO => 'zero_statements', self::NEGATIVE => 'negative_carried', self::HELD => 'held', self::BELOW_MINIMUM => 'below_minimum', default => null,
            };
            if ($counted !== null) {
                $summary[$counted]++;
            }
            $summary['lines'] += in_array($result['outcome'], [self::SETTLEMENT, self::ZERO], true) ? (int) $result['line_count'] : 0;
        }
        $run = null;
        if (! $dryRun || $admin !== null) {
            $run = new FinanceRun;
            $run->forceFill([
                'kind' => FinanceRun::SETTLEMENT_GENERATION, 'status' => 'OK', 'triggered_by' => $admin === null ? $trigger : FinanceRun::BY_ADMIN, 'admin_user_id' => $admin?->getKey(),
                'dry_run' => $dryRun, 'parameters' => ['until' => $until === null ? null : CarbonImmutable::instance($until)->utc()->toIso8601String(), 'cadence' => config('finance.settlements.cadence')],
                'result' => $summary, 'problems' => $summary['negative_carried'], 'started_at' => $started, 'finished_at' => now(),
            ])->save();
        }

        return ['run' => $run, 'results' => $results, 'summary' => $summary];
    }

    /**
     * @return array<string, mixed>
     */
    private function one(RestaurantLocation $location, string $currency, CarbonImmutable $cutoff, string $timezone, bool $dryRun, ?AdminUser $admin): array
    {
        return DB::transaction(function () use ($location, $currency, $cutoff, $timezone, $dryRun, $admin): array {
            // One calculation per location and currency at a time — also across processes.
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['finance:settlement:'.$location->getKey().':'.$currency]);
            $base = ['location' => (string) $location->public_id, 'restaurant' => (string) $location->name, 'currency' => $currency, 'period_end' => $cutoff->toIso8601String(), 'dry_run' => $dryRun, 'settlement' => null];
            if ($this->holds->locationHeld((int) $location->getKey())) {
                return [...$base, 'outcome' => self::HELD, 'net_payable_minor' => null, 'line_count' => 0];
            }
            $items = RestaurantPayableItem::query()->settleable()->where('location_id', $location->getKey())->where('currency', $currency)->where('available_at', '<', $cutoff)
                ->orderBy('available_at')->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                return [...$base, 'outcome' => self::NOTHING, 'net_payable_minor' => 0, 'line_count' => 0];
            }
            $totals = self::totals($items);
            $base = [...$base, 'net_payable_minor' => $totals['net_payable_minor'], 'line_count' => $items->count(), 'totals' => $totals];
            $negativeKey = 'negative-balance:'.$location->public_id.':'.$currency;

            if ($totals['net_payable_minor'] < 0) {
                // Nothing is consumed: the lines wait for earnings that cover them. No payout, no debit, no collection.
                if (! $dryRun) {
                    $this->differences->report(DifferenceType::NegativeRestaurantBalance, $negativeKey, [
                        'subject_type' => 'restaurant_location', 'subject_id' => (int) $location->getKey(), 'subject_public_id' => (string) $location->public_id, 'subject_reference' => (string) $location->name,
                        'market_id' => $location->market_id, 'location_id' => (int) $location->getKey(), 'currency' => $currency, 'expected_minor' => 0, 'actual_minor' => $totals['net_payable_minor'],
                        'detail' => ['policy' => 'CARRY_FORWARD', 'note' => 'FINANCE REVIEW REQUIRED: the restaurant owes the platform. Nothing is debited or collected automatically.'],
                    ]);
                    $this->metrics->record('settlement_negative_carried');
                }

                return [...$base, 'outcome' => self::NEGATIVE];
            }
            if (! $dryRun) {
                $this->differences->settled($negativeKey, 'The restaurant\'s lines no longer add up to a negative amount.');
            }
            $minimum = config('finance.settlements.minimum_payout_minor');
            if ($minimum !== null && $totals['net_payable_minor'] > 0 && $totals['net_payable_minor'] < (int) $minimum) {
                return [...$base, 'outcome' => self::BELOW_MINIMUM];
            }
            $outcome = $totals['net_payable_minor'] === 0 ? self::ZERO : self::SETTLEMENT;
            if ($dryRun) {
                return [...$base, 'outcome' => $outcome];
            }

            $previous = RestaurantSettlement::query()->where('location_id', $location->getKey())->where('currency', $currency)->where('status', '<>', SettlementStatus::Cancelled->value)->max('period_end');
            $earliest = CarbonImmutable::instance($items->first()->available_at);
            $start = $previous === null ? $earliest : CarbonImmutable::parse($previous, 'UTC');
            if ($start->greaterThanOrEqualTo($cutoff) || $earliest->lessThan($start)) {
                $start = $earliest->lessThan($cutoff) ? $earliest : $cutoff->subSecond();
            }
            $now = now();
            $settlement = new RestaurantSettlement;
            $settlement->forceFill([
                'reference' => 'STL-'.$cutoff->setTimezone($timezone)->format('Ymd').'-'.Str::upper(Str::random(6)),
                'organization_id' => $location->organization_id,
                'location_id' => $location->getKey(),
                'market_id' => $location->market_id,
                'restaurant_name' => (string) $location->name,
                'currency' => $currency,
                'cadence' => (string) config('finance.settlements.cadence'),
                'timezone' => $timezone,
                'period_start' => $start,
                'period_end' => $cutoff,
                'status' => $outcome === self::ZERO ? SettlementStatus::Closed : SettlementStatus::Calculated,
                ...$totals,
                'line_count' => $items->count(),
                'generated_by_type' => $admin === null ? RestaurantSettlement::BY_SYSTEM : RestaurantSettlement::BY_ADMIN,
                'generated_by_admin_id' => $admin?->getKey(),
                'generated_at' => $now,
            ])->save();

            $stamp = CarbonImmutable::instance($now)->utc()->format('Y-m-d H:i:sP');
            $lines = [];
            foreach ($items->values() as $index => $item) {
                $lines[] = [
                    'settlement_id' => $settlement->getKey(),
                    'payable_item_id' => $item->getKey(),
                    'line_no' => $index + 1,
                    'type' => $item->type,
                    'description_code' => $item->description_code,
                    'order_id' => $item->order_id,
                    'order_number' => $item->order_number,
                    'source_public_id' => $item->public_id,
                    'currency' => $currency,
                    'gross_minor' => $item->gross_minor,
                    'restaurant_discount_minor' => $item->restaurant_discount_minor,
                    'commission_minor' => $item->commission_minor,
                    'tax_passed_minor' => $item->tax_passed_minor,
                    'fee_passed_minor' => $item->fee_passed_minor,
                    'amount_minor' => $item->amount_minor,
                    'occurred_at' => CarbonImmutable::instance($item->available_at)->utc()->format('Y-m-d H:i:sP'),
                    'created_at' => $stamp,
                ];
            }
            foreach (array_chunk($lines, 500) as $chunk) {
                DB::table('restaurant_settlement_lines')->insert($chunk);
            }
            RestaurantPayableItem::query()->whereKey($items->modelKeys())->update(['status' => RestaurantPayableItem::STATUS_SETTLED, 'settlement_id' => $settlement->getKey(), 'settled_at' => $now, 'updated_at' => $now]);

            $this->audit->record('finance.settlement_generated', $settlement, $admin, [
                'status' => ['from' => null, 'to' => $settlement->status->value],
                'net_payable_minor' => ['from' => null, 'to' => $totals['net_payable_minor']],
                'line_count' => ['from' => null, 'to' => $items->count()],
                'period_end' => ['from' => null, 'to' => $cutoff->toIso8601String()],
            ], null, $location->market_id);
            $this->metrics->record('settlement_generated');

            return [...$base, 'outcome' => $outcome, 'settlement' => (string) $settlement->public_id, 'reference' => $settlement->reference];
        });
    }

    /**
     * The statement's totals: nothing but sums of its lines.
     *
     * @param  iterable<RestaurantPayableItem|RestaurantSettlementLine>  $lines
     * @return array{order_count: int, gross_sales_minor: int, restaurant_discount_minor: int, commission_minor: int, tax_passed_minor: int, fee_passed_minor: int, refund_adjustments_minor: int, other_adjustments_minor: int, net_payable_minor: int}
     */
    public static function totals(iterable $lines): array
    {
        $t = ['order_count' => 0, 'gross_sales_minor' => 0, 'restaurant_discount_minor' => 0, 'commission_minor' => 0, 'tax_passed_minor' => 0, 'fee_passed_minor' => 0, 'refund_adjustments_minor' => 0, 'other_adjustments_minor' => 0, 'net_payable_minor' => 0];
        foreach ($lines as $line) {
            if ($line->type === RestaurantPayableItem::TYPE_ORDER_EARNING) {
                $t['order_count']++;
                $t['gross_sales_minor'] += (int) $line->gross_minor;
                $t['restaurant_discount_minor'] += (int) $line->restaurant_discount_minor;
                $t['commission_minor'] += (int) $line->commission_minor;
                $t['tax_passed_minor'] += (int) $line->tax_passed_minor;
                $t['fee_passed_minor'] += (int) $line->fee_passed_minor;
            } elseif ($line->type === RestaurantPayableItem::TYPE_REFUND_ADJUSTMENT) {
                $t['refund_adjustments_minor'] += (int) $line->amount_minor;
            } else {
                $t['other_adjustments_minor'] += (int) $line->amount_minor;
            }
            $t['net_payable_minor'] += (int) $line->amount_minor;
        }

        return $t;
    }
}
