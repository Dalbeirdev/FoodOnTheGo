<?php

namespace App\Services\Analytics;

/**
 * Payment and pickup analytics (Module 38).
 *
 * PAYMENTS are the payment domain's attempts, by the day they were created. "Verified" is the backend's own
 * verification against the provider — never something a client reported. An attempt that is still pending or whose
 * outcome is unknown is in neither the numerator nor the denominator of the success rate. Only normalized
 * categories are shown: no provider payload, no card, no UPI handle.
 *
 * PICKUP is what orders chose (order domain), what the backend refused (server-recorded) and, only where a
 * restaurant configured a capacity per slot, how much of it orders used. Nothing is made up for unlimited capacity.
 * There is no delivery, so there are no delivery figures.
 */
final class PaymentAnalyticsService
{
    private const PENDING = ['CREATED', 'PROVIDER_READY', 'PROCESSING', 'PENDING', 'AUTHORIZED'];

    private const UNKNOWN = ['UNKNOWN', 'REVIEW_REQUIRED'];

    public function __construct(private readonly ReadModel $read) {}

    /**
     * @return array<string, mixed>
     */
    public function payments(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->read->breakdown('payments', 'status', $scope, $period);
        $before = $this->read->breakdown('payments', 'status', $scope, $period->previous());
        $n = fn (array $rows, array $statuses): int => array_sum(array_map(fn (string $s): int => $rows[$s]['count'] ?? 0, $statuses));
        $final = ['VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED'];
        $verified = array_map(fn (array $v): int => $v['VERIFIED'] ?? 0, $this->read->breakdownAmounts('payments', 'status', $scope, $period));
        $methods = $this->read->breakdown('payments', 'method', $scope, $period);
        $finalized = $this->read->breakdown('payments', 'method_finalized', $scope, $period);
        $providers = $this->read->breakdown('payments', 'provider', $scope, $period);

        return [
            'metrics' => [
                'payment_attempts' => Metric::count('payment_attempts', $n($now, array_keys($now)), $n($before, array_keys($before))),
                'payments_verified' => Metric::count('payments_verified', $n($now, ['VERIFIED']), $n($before, ['VERIFIED'])),
                'payments_failed' => Metric::count('payments_failed', $n($now, ['FAILED']), $n($before, ['FAILED'])),
                'payments_cancelled' => Metric::count('payments_cancelled', $n($now, ['CANCELLED']), $n($before, ['CANCELLED'])),
                'payments_expired' => Metric::count('payments_expired', $n($now, ['EXPIRED']), $n($before, ['EXPIRED'])),
                'payments_pending' => Metric::count('payments_pending', $n($now, self::PENDING)),
                'payments_unknown' => Metric::count('payments_unknown', $n($now, self::UNKNOWN)),
                'payment_success_rate' => Metric::ratio('payment_success_rate', $n($now, ['VERIFIED']), $n($now, $final), [$n($before, ['VERIFIED']), $n($before, $final)]),
                'payments_verified_amount' => Metric::money('payments_verified_amount', $verified, null, $scope->currency),
                'payment_verification_time' => Metric::duration('payment_verification_time', $now['VERIFIED']['seconds_sum'] ?? 0, $now['VERIFIED']['seconds_count'] ?? 0),
            ],
            'status_distribution' => ReadModel::rows($now),
            // The method the PROVIDER reported for a verified payment; a method's success rate over its finished attempts.
            'methods' => array_map(fn (array $row): array => $row + [
                'verified' => $methods[$row['key']]['count'] ?? 0,
                'success_rate' => Metric::ratio('payment_success_rate', $methods[$row['key']]['count'] ?? 0, $row['count']),
            ], ReadModel::rows($finalized + array_diff_key($methods, $finalized))),
            'failure_categories' => ReadModel::rows($this->read->breakdown('payments', 'failure_category', $scope, $period)),
            'providers' => array_map(fn (array $row): array => $row + ['preparation_time' => Metric::duration('payment_verification_time', $providers[$row['key']]['seconds_sum'], $providers[$row['key']]['seconds_count'])['average']], ReadModel::rows($providers)),
            'provider_note' => 'On this machine payments run on the DEVELOPMENT provider. Provider preparation time is created → the provider being ready.',
            'series' => $this->series($scope, $period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function series(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $bucket = $period->bucket() === 'hour' ? 'day' : $period->bucket();
        $points = array_fill_keys($period->buckets($bucket), ['attempts' => 0, 'verified' => 0]);
        $rows = $this->read->daily('analytics_daily_breakdowns', $scope, $period)->where('t.grp', 'payments')->where('t.dimension', 'status')
            ->selectRaw("t.local_date, coalesce(sum(t.count), 0) as attempts, coalesce(sum(t.count) filter (where t.value = 'VERIFIED'), 0) as verified")->groupBy('t.local_date')->get();
        foreach ($rows as $row) {
            $key = $period->bucketOf(substr((string) $row->local_date, 0, 10), $bucket);
            $points[$key]['attempts'] += (int) $row->attempts;
            $points[$key]['verified'] += (int) $row->verified;
        }

        return ['bucket' => $bucket, 'timezone' => $period->timezone, 'points' => array_map(fn (string $key, array $row): array => ['bucket' => $key] + $row, array_keys($points), array_values($points))];
    }

    /**
     * @return array<string, mixed>
     */
    public function pickup(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $modes = $this->read->breakdown('pickup', 'mode', $scope, $period);
        $refused = $this->read->breakdown('pickup', 'refused', $scope, $period);
        $capacity = $this->read->breakdown('pickup', 'capacity', $scope, $period);
        $recommended = $this->read->breakdown('pickup', 'recommended', $scope, $period);
        $lead = ['sum' => array_sum(array_column($modes, 'seconds_sum')), 'count' => array_sum(array_column($modes, 'seconds_count'))];
        $configured = isset($capacity['units_capacity']) && $capacity['units_capacity']['count'] > 0;

        return [
            'metrics' => [
                'pickup_asap_orders' => Metric::count('pickup_asap_orders', $modes['ASAP']['count'] ?? 0),
                'pickup_scheduled_orders' => Metric::count('pickup_scheduled_orders', $modes['SCHEDULED']['count'] ?? 0),
                'pickup_lead_time' => Metric::duration('pickup_lead_time', $lead['sum'], $lead['count']),
                'pickup_refusals' => Metric::count('pickup_refusals', array_sum(array_column($refused, 'count'))),
                'pickup_slot_full' => Metric::count('pickup_slot_full', $refused['SLOT_FULL']['count'] ?? 0),
                'recommended_slot_acceptance' => Metric::ratio('recommended_slot_acceptance', $recommended['true']['count'] ?? 0, ($recommended['true']['count'] ?? 0) + ($recommended['false']['count'] ?? 0)),
                'pickup_capacity_utilization' => $configured
                    ? Metric::ratio('pickup_capacity_utilization', $capacity['units_used']['count'] ?? 0, $capacity['units_capacity']['count'])
                    : Metric::ratio('pickup_capacity_utilization', 0, 0),
            ],
            'modes' => array_map(fn (array $row): array => $row + ['lead_time_average' => Metric::duration('pickup_lead_time', $modes[$row['key']]['seconds_sum'], $modes[$row['key']]['seconds_count'])['average']], ReadModel::rows($modes)),
            'methods' => ReadModel::rows($this->read->breakdown('pickup', 'method', $scope, $period), ['COUNTER' => 'Counter', 'CURBSIDE' => 'Curbside', 'DRIVE_THROUGH' => 'Drive-through']),
            'refusal_reasons' => ReadModel::rows($refused),
            'capacity' => [
                'configured' => $configured,
                'slots_with_orders' => $configured ? ($capacity['slots_used']['count'] ?? 0) : null,
                'slots_full' => $configured ? ($capacity['slots_full']['count'] ?? 0) : null,
                'units_used' => $configured ? ($capacity['units_used']['count'] ?? 0) : null,
                'units_capacity' => $configured ? $capacity['units_capacity']['count'] : null,
                'note' => $configured ? 'Only slots that had at least one order, at restaurants with a configured capacity per slot.' : 'No capacity per slot is configured in this scope: capacity is unlimited and no utilization is shown.',
            ],
            'delivery' => 'Not offered: there are no delivery figures.',
        ];
    }
}
