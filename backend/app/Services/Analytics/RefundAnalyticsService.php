<?php

namespace App\Services\Analytics;

/**
 * Refund analytics (Module 38) — read from the REFUND domain, never guessed from an order's status.
 *
 * Two days matter and are kept apart: the day a refund was ASKED for (where it stands, why it was asked) and the day
 * it COMPLETED (the money that went back, how long it took). And there are two refund rates with two names: orders
 * with a refund per order placed, and refund amount per verified payment amount.
 */
final class RefundAnalyticsService
{
    private const IN_PROGRESS = ['REQUIRED', 'REQUESTED', 'APPROVED', 'SUBMITTING', 'SUBMISSION_UNKNOWN', 'PROCESSING', 'REVIEW_REQUIRED'];

    public function __construct(private readonly ReadModel $read, private readonly OrderAnalyticsService $orders) {}

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $completed = $this->read->breakdown('refunds', 'completed', $scope, $period);
        $before = $this->read->breakdown('refunds', 'completed', $scope, $period->previous());
        $status = $this->read->breakdown('refunds', 'status', $scope, $period);
        $amounts = array_map('array_sum', $this->read->breakdownAmounts('refunds', 'completed', $scope, $period));
        $amountsBefore = array_map('array_sum', $this->read->breakdownAmounts('refunds', 'completed', $scope, $period->previous()));
        $verified = array_map(fn (array $v): int => $v['VERIFIED'] ?? 0, $this->read->breakdownAmounts('payments', 'status', $scope, $period));
        $orders = OrderAnalyticsService::flat($this->orders->totals($scope, $period)) + ['orders_placed' => 0, 'cohort_refunded' => 0];
        $count = fn (array $rows): int => array_sum(array_column($rows, 'count'));
        $single = count($amounts) <= 1 && count($verified) <= 1;

        return [
            'metrics' => [
                'refunds_completed' => Metric::count('refunds_completed', $count($completed), $count($before)),
                'refund_amount' => Metric::money('refund_amount', $amounts, $amountsBefore, $scope->currency),
                'refunds_full' => Metric::count('refunds_full', $completed['FULL']['count'] ?? 0),
                'refunds_partial' => Metric::count('refunds_partial', $completed['PARTIAL']['count'] ?? 0),
                'refunds_in_progress' => Metric::count('refunds_in_progress', array_sum(array_map(fn (string $s): int => $status[$s]['count'] ?? 0, self::IN_PROGRESS))),
                'refunds_failed' => Metric::count('refunds_failed', $status['FAILED']['count'] ?? 0),
                'refund_processing_time' => Metric::duration('refund_processing_time', array_sum(array_column($completed, 'seconds_sum')), array_sum(array_column($completed, 'seconds_count'))),
                'refund_rate_orders' => Metric::ratio('refund_rate_orders', $orders['cohort_refunded'], $orders['orders_placed']),
                // An amount over an amount is a rate only inside one currency.
                'refund_rate_amount' => $single ? Metric::ratio('refund_rate_amount', (int) array_sum($amounts), (int) array_sum($verified)) : Metric::ratio('refund_rate_amount', 0, 0) + ['mixed_currencies' => true],
            ],
            'status_distribution' => ReadModel::rows($status),
            'reasons' => ReadModel::rows($this->read->breakdown('refunds', 'reason', $scope, $period)),
            'kinds' => ReadModel::rows($completed, ['FULL' => 'Full refund', 'PARTIAL' => 'Partial refund']),
            'by_request_day_note' => 'Status and reasons count refunds by the day they were asked for; completed refunds and their amount by the day they completed.',
            'source' => 'Refund domain (refunds). The ledger\'s own refund figure is in the finance report.',
        ];
    }
}
