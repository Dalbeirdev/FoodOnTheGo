<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Support, review and notification analytics (Module 38) — each read from its own domain.
 *
 * SUPPORT: counts and times of cases. The times are wall-clock; the internal targets behind "missed" and "at risk"
 * are TEST FIXTURES and promise nothing to anyone. There is deliberately no figure per agent: a case count or a
 * response time of one person is not a measure of that person, and no such score was asked for.
 *
 * REVIEWS: published reviews only — a hidden, rejected or removed review is in no figure.
 *
 * NOTIFICATIONS: "sent" means the provider accepted a push. It is never shown as "read"; the only read figure is the
 * in-app read mark. Opening a push is not tracked, so there is no push open rate.
 */
final class SupportAnalyticsService
{
    public function __construct(private readonly ReadModel $read) {}

    /**
     * @return array<string, mixed>
     */
    public function support(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $columns = ['created', 'resolved', 'closed', 'reopened', 'escalated', 'sla_breached', 'first_response_seconds_sum', 'first_response_count', 'resolution_seconds_sum', 'resolution_count'];
        $now = $this->read->sums('analytics_daily_support', $scope, $period, $columns);
        $before = $this->read->sums('analytics_daily_support', $scope, $period->previous(), $columns);
        $by = fn (string $column): array => $this->read->daily('analytics_daily_support', $scope, $period)->selectRaw("t.{$column} as k, coalesce(sum(t.created), 0) as created, coalesce(sum(t.resolved), 0) as resolved")->groupBy('t.'.$column)->orderByRaw('2 desc, 1')->get()
            ->map(fn ($r): array => ['key' => (string) $r->k, 'label' => ReadModel::words((string) $r->k), 'created' => (int) $r->created, 'resolved' => (int) $r->resolved])->all();
        $series = $this->read->series('analytics_daily_support', $scope, $period, ['created', 'resolved']);
        $live = $this->liveCases($scope);

        return [
            'metrics' => [
                'support_cases_created' => Metric::count('support_cases_created', $now['created'], $before['created']),
                'support_cases_resolved' => Metric::count('support_cases_resolved', $now['resolved'], $before['resolved']),
                'support_cases_closed' => Metric::count('support_cases_closed', $now['closed'], $before['closed']),
                'support_cases_reopened' => Metric::count('support_cases_reopened', $now['reopened'], $before['reopened']),
                'support_cases_escalated' => Metric::count('support_cases_escalated', $now['escalated'], $before['escalated']),
                'support_sla_breached' => Metric::count('support_sla_breached', $now['sla_breached'], $before['sla_breached']),
                'support_reopen_rate' => Metric::ratio('support_reopen_rate', $now['reopened'], $now['resolved'], [$before['reopened'], $before['resolved']]),
                'support_first_response_time' => Metric::duration('support_first_response_time', $now['first_response_seconds_sum'], $now['first_response_count'], null, null, [$before['first_response_seconds_sum'], $before['first_response_count']]),
                'support_resolution_time' => Metric::duration('support_resolution_time', $now['resolution_seconds_sum'], $now['resolution_count'], null, null, [$before['resolution_seconds_sum'], $before['resolution_count']]),
            ] + $live,
            'categories' => $by('category'),
            'priorities' => $by('priority'),
            'series' => ['bucket' => $period->bucket() === 'hour' ? 'day' : $period->bucket(), 'timezone' => $period->timezone, 'points' => array_map(fn (string $key, array $row): array => ['bucket' => $key] + $row, array_keys($series), array_values($series))],
            'targets_note' => 'Internal targets on TEST FIXTURE policies. Not contractual; never shown to a customer or a restaurant.',
            'no_agent_ranking' => 'There is no figure per agent: no employee score was asked for or approved.',
            'trend_note' => 'A rise in cases about a restaurant is information for a person. Nothing is done to a restaurant because of it.',
        ];
    }

    /**
     * What the overview shows of support: the desk now, and the period's new cases and first-response time.
     *
     * @return array<string, array<string, mixed>>
     */
    public function headline(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $now = $this->read->sums('analytics_daily_support', $scope, $period, ['created', 'first_response_seconds_sum', 'first_response_count']);

        return $this->liveCases($scope) + [
            'support_cases_created' => Metric::count('support_cases_created', $now['created']),
            'support_first_response_time' => Metric::duration('support_first_response_time', $now['first_response_seconds_sum'], $now['first_response_count']),
        ];
    }

    /**
     * The desk at this moment, from the cases themselves.
     *
     * @return array<string, array<string, mixed>>
     */
    public function liveCases(AnalyticsScope $scope): array
    {
        $query = DB::table('support_cases as t')->whereNotIn('t.status', ['RESOLVED', 'CLOSED', 'CANCELLED']);
        $this->read->narrow($query, $scope);
        $row = $query->selectRaw("count(*) as open, count(*) filter (where t.assigned_admin_id is null) as unassigned, count(*) filter (where t.priority = 'URGENT') as urgent,
            count(*) filter (where t.sla_status = 'AT_RISK') as at_risk, count(*) filter (where t.sla_status = 'BREACHED') as breached")->first();

        return [
            'support_open_cases' => Metric::count('support_open_cases', (int) ($row->open ?? 0)),
            'support_unassigned_cases' => Metric::count('support_unassigned_cases', (int) ($row->unassigned ?? 0)),
            'support_urgent_cases' => Metric::count('support_urgent_cases', (int) ($row->urgent ?? 0)),
            'support_sla_at_risk' => Metric::count('support_sla_at_risk', (int) ($row->at_risk ?? 0)),
            'support_sla_breached_open' => Metric::count('support_sla_breached_open', (int) ($row->breached ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reviews(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $columns = ['reviews_published', 'rating_sum', 'star_1', 'star_2', 'star_3', 'star_4', 'star_5', 'review_responses', 'review_reports'];
        $now = $this->read->sums('analytics_daily_restaurant', $scope, $period, $columns);
        $before = $this->read->sums('analytics_daily_restaurant', $scope, $period->previous(), $columns);
        $series = $this->read->series('analytics_daily_restaurant', $scope, $period, ['reviews_published', 'rating_sum']);

        return [
            'metrics' => [
                'reviews_published' => Metric::count('reviews_published', $now['reviews_published'], $before['reviews_published']),
                'average_rating' => Metric::rating('average_rating', $now['rating_sum'], $now['reviews_published']) + ['previous' => Metric::rating('average_rating', $before['rating_sum'], $before['reviews_published'])['value']],
                'review_response_rate' => Metric::ratio('review_response_rate', $now['review_responses'], $now['reviews_published'], [$before['review_responses'], $before['reviews_published']]),
                'reviews_reported' => Metric::count('reviews_reported', $now['review_reports'], $before['review_reports']),
            ],
            'distribution' => array_map(fn (int $stars): array => ['stars' => $stars, 'reviews' => $now['star_'.$stars]], [5, 4, 3, 2, 1]),
            'series' => ['bucket' => $period->bucket() === 'hour' ? 'day' : $period->bucket(), 'timezone' => $period->timezone,
                'points' => array_map(fn (string $key, array $row): array => ['bucket' => $key, 'reviews_published' => $row['reviews_published'], 'average_rating' => $row['reviews_published'] > 0 ? round($row['rating_sum'] / $row['reviews_published'], 2) : null], array_keys($series), array_values($series))],
            'excluded' => 'Reviews that are hidden, rejected, removed or waiting for moderation are in no figure.',
            'alert_note' => 'A falling rating is information for a person. Nothing is done to a restaurant because of it.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notifications(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $created = $this->read->breakdown('notifications', 'created', $scope, $period);
        $before = $this->read->breakdown('notifications', 'created', $scope, $period->previous());
        $read = $this->read->breakdown('notifications', 'in_app_read', $scope, $period);
        $delivery = $this->read->breakdown('notifications', 'delivery', $scope, $period);
        $policy = $this->read->breakdown('notifications', 'policy', $scope, $period);
        $latency = $this->read->breakdown('notifications', 'latency_ms', $scope, $period);
        $n = fn (array $rows): int => array_sum(array_column($rows, 'count'));
        $push = fn (array $statuses): int => array_sum(array_map(fn (string $s): int => $delivery['PUSH:'.$s]['count'] ?? 0, $statuses));
        $pushLatency = array_filter($latency, fn (string $key): bool => str_starts_with($key, 'PUSH:'), ARRAY_FILTER_USE_KEY);
        $channels = [];
        foreach ($delivery as $key => $figures) {
            [$channel, $status] = explode(':', $key, 2) + [1 => 'UNKNOWN'];
            $channels[$channel] ??= ['channel' => $channel, 'statuses' => []];
            $channels[$channel]['statuses'][] = ['key' => $status, 'label' => ReadModel::words($status), 'count' => $figures['count']];
        }

        return [
            'metrics' => [
                'notifications_created' => Metric::count('notifications_created', $n($created), $n($before)),
                'push_queued' => Metric::count('push_queued', $push(['QUEUED', 'SENDING', 'RETRY_SCHEDULED'])),
                'push_sent' => Metric::count('push_sent', $push(['SENT', 'DELIVERED'])),
                'push_failed' => Metric::count('push_failed', $push(['FAILED'])),
                'push_invalid_tokens' => Metric::count('push_invalid_tokens', $push(['INVALID_DESTINATION'])),
                'push_preference_skips' => Metric::count('push_preference_skips', $push(['SKIPPED'])),
                'in_app_read' => Metric::count('in_app_read', $n($read)),
                'in_app_read_rate' => Metric::ratio('in_app_read_rate', $n($read), $n($created)),
                'push_provider_latency' => Metric::duration('push_provider_latency', array_sum(array_column($pushLatency, 'seconds_sum')), array_sum(array_column($pushLatency, 'seconds_count'))),
            ],
            'categories' => array_map(fn (array $row): array => $row + ['in_app_read' => $read[$row['key']]['count'] ?? 0], ReadModel::rows($created)),
            'channels' => array_values($channels),
            'skip_reasons' => ReadModel::rows($policy),
            'errors' => ReadModel::rows($this->read->breakdown('notifications', 'error', $scope, $period)),
            'push_read' => ['tracked' => false, 'statement' => 'NOT TRACKED. "Sent" means the push provider accepted the message; whether anybody opened or read a push is not recorded and is not inferred.'],
            'provider_note' => 'On this machine push runs on the DEVELOPMENT provider; e-mail and SMS providers are not configured.',
        ];
    }
}
