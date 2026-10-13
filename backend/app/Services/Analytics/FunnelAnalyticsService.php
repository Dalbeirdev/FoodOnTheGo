<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * The customer funnel (Module 38): Journey search → Route results → Restaurant viewed → Menu viewed → Added to cart
 * → Pickup selected → Checkout started → Payment started → Payment verified → Order created → Order completed.
 *
 * The unit is the SESSION. A session reaches a stage only after the one before it, and only within its own bounded
 * life — a search in January never converts against an order in October. Stages 1–8 are behavioural events; stages
 * 9–11 are read from the payment and order domains, so a client cannot fake a conversion.
 *
 * Three different numbers are returned per stage and never mixed: sessions (the funnel), signed-in customers, and
 * events (every occurrence, repeats included). Every conversion divides sessions by sessions.
 */
final class FunnelAnalyticsService
{
    public function __construct(private readonly ReadModel $read) {}

    /**
     * @return array<string, mixed>
     */
    public function report(AnalyticsScope $scope, AnalyticsPeriod $period, ?string $platform = null): array
    {
        $definitions = (array) config('analytics.funnel.stages');
        $sessions = $this->counts($scope, $period, $platform);
        $previous = $this->counts($scope, $period->previous(), $platform);
        $customers = $this->customers($scope, $period, $platform);
        $stages = [];
        foreach ($definitions as $number => $definition) {
            $number = (int) $number;
            $here = $sessions[$number]['sessions'] ?? 0;
            $before = $number === 1 ? null : ($sessions[$number - 1]['sessions'] ?? 0);
            $next = $number === 11 ? null : ($sessions[$number + 1]['sessions'] ?? 0);
            $stages[] = [
                'stage' => $number, 'key' => $definition['key'], 'label' => $definition['label'], 'source' => $definition['source'], 'event' => $definition['event'],
                'sessions' => $here, 'customers' => $customers[$number] ?? 0, 'events' => $definition['event'] === null ? null : ($sessions[$number]['events'] ?? 0),
                'conversion_from_previous' => $before === null ? null : Metric::ratio('funnel_stage_conversion', $here, $before),
                'conversion_overall' => Metric::ratio('funnel_overall_conversion', $here, $sessions[1]['sessions'] ?? 0),
                // Sessions that came this far and no further — "did not continue", not a failure.
                'did_not_continue' => $next === null ? null : max(0, $here - $next),
            ];
        }
        $top = $sessions[1]['sessions'] ?? 0;
        $done = $sessions[11]['sessions'] ?? 0;

        return [
            'unit' => 'SESSION',
            'metrics' => [
                'funnel_sessions' => Metric::count('funnel_sessions', $top, $previous[1]['sessions'] ?? 0),
                'funnel_overall_conversion' => Metric::ratio('funnel_overall_conversion', $done, $top, [$previous[11]['sessions'] ?? 0, $previous[1]['sessions'] ?? 0]),
            ],
            'stages' => $stages,
            'abandonment' => [
                ['key' => 'journey_to_restaurant', 'label' => 'Searched a journey, opened no restaurant', 'sessions' => max(0, ($sessions[1]['sessions'] ?? 0) - ($sessions[3]['sessions'] ?? 0))],
                ['key' => 'restaurant_to_cart', 'label' => 'Opened a restaurant, added nothing to a cart', 'sessions' => max(0, ($sessions[3]['sessions'] ?? 0) - ($sessions[5]['sessions'] ?? 0))],
                ['key' => 'cart_to_checkout', 'label' => 'Added to a cart, started no checkout', 'sessions' => max(0, ($sessions[5]['sessions'] ?? 0) - ($sessions[7]['sessions'] ?? 0))],
                ['key' => 'checkout_to_payment', 'label' => 'Started a checkout, started no payment', 'sessions' => max(0, ($sessions[7]['sessions'] ?? 0) - ($sessions[8]['sessions'] ?? 0))],
                ['key' => 'payment_to_order', 'label' => 'Started a payment, no order was created', 'sessions' => max(0, ($sessions[8]['sessions'] ?? 0) - ($sessions[10]['sessions'] ?? 0))],
            ],
            'window' => [
                'session_idle_minutes' => (int) config('analytics.session.idle_minutes'), 'session_max_hours' => (int) config('analytics.session.max_hours'),
                'conversion_window_hours' => (int) config('analytics.funnel.conversion_window_hours'),
                'cohort' => 'A session belongs to the day of its first event; its later stages count for that day.',
            ],
            'platforms' => $this->platforms($scope, $period),
            'historical_note' => 'Behavioural events are recorded from Module 38 on. Earlier periods have no funnel: HISTORICAL DATA UNAVAILABLE BEFORE MODULE 38.',
        ];
    }

    /**
     * The funnel as the overview shows it: sessions per stage and the two conversions — no customers, no platforms.
     *
     * @return array<string, mixed>
     */
    public function headline(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        $sessions = $this->counts($scope, $period);
        $stages = [];
        foreach ((array) config('analytics.funnel.stages') as $number => $definition) {
            $number = (int) $number;
            $here = $sessions[$number]['sessions'] ?? 0;
            $stages[] = ['stage' => $number, 'key' => $definition['key'], 'label' => $definition['label'], 'sessions' => $here,
                'conversion_from_previous' => $number === 1 ? null : Metric::ratio('funnel_stage_conversion', $here, $sessions[$number - 1]['sessions'] ?? 0),
                'conversion_overall' => Metric::ratio('funnel_overall_conversion', $here, $sessions[1]['sessions'] ?? 0)];
        }

        return ['unit' => 'SESSION', 'stages' => $stages, 'metrics' => [
            'funnel_sessions' => Metric::count('funnel_sessions', $sessions[1]['sessions'] ?? 0),
            'funnel_overall_conversion' => Metric::ratio('funnel_overall_conversion', $sessions[11]['sessions'] ?? 0, $sessions[1]['sessions'] ?? 0),
        ]];
    }

    /**
     * Sessions and events per stage, from the daily funnel (sessions of a day's cohort can be added up).
     *
     * @return array<int, array{sessions: int, events: int}>
     */
    public function counts(AnalyticsScope $scope, AnalyticsPeriod $period, ?string $platform = null): array
    {
        $rows = $this->read->daily('analytics_daily_funnel', $this->marketOnly($scope), $period)->when($platform !== null, fn ($q) => $q->where('t.platform', $platform))
            ->selectRaw('t.stage, coalesce(sum(t.sessions), 0) as sessions, coalesce(sum(t.events), 0) as events')->groupBy('t.stage')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->stage] = ['sessions' => (int) $row->sessions, 'events' => (int) $row->events];
        }

        return $out;
    }

    /**
     * Distinct signed-in customers per stage over the whole period — counted over the session facts, because a
     * customer of two days must count once.
     *
     * @return array<int, int>
     */
    private function customers(AnalyticsScope $scope, AnalyticsPeriod $period, ?string $platform): array
    {
        $select = implode(', ', array_map(fn (int $k): string => "count(distinct customer_key) filter (where stage >= {$k} and customer_key is not null) as s{$k}", range(1, 11)));
        $row = DB::table('analytics_session_facts')->whereIn('market_id', $scope->marketIds === [] ? [0] : $scope->marketIds)->whereBetween('local_date', [$period->fromDate(), $period->toDate()])
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform))->selectRaw($select)->first();
        $out = [];
        foreach (range(1, 11) as $k) {
            $out[$k] = (int) ($row->{'s'.$k} ?? 0);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function platforms(AnalyticsScope $scope, AnalyticsPeriod $period): array
    {
        return $this->read->daily('analytics_daily_funnel', $this->marketOnly($scope), $period)->whereIn('t.stage', [1, 11])
            ->selectRaw('t.platform, coalesce(sum(t.sessions) filter (where t.stage = 1), 0) as searched, coalesce(sum(t.sessions) filter (where t.stage = 11), 0) as completed')->groupBy('t.platform')->orderBy('t.platform')->get()
            ->map(fn ($r): array => ['platform' => (string) $r->platform, 'sessions' => (int) $r->searched, 'completed' => (int) $r->completed, 'conversion' => Metric::ratio('funnel_overall_conversion', (int) $r->completed, (int) $r->searched)])->all();
    }

    /** The funnel is a market's: it has no restaurant grain. */
    private function marketOnly(AnalyticsScope $scope): AnalyticsScope
    {
        return new AnalyticsScope($scope->audience, $scope->marketIds, null, $scope->timezone, $scope->currency, $scope->label);
    }
}
