<?php

namespace App\Services\Analytics\Aggregation;

use App\Services\Analytics\Bands;
use App\Services\Analytics\EventRegistry;
use App\Services\Analytics\Pseudonymizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The two fact tables of analytics (Module 38), each a COPY that can be thrown away and made again:
 *
 *   analytics_order_facts    one row per order — the order's own status, timestamps and amounts, the commission and
 *                            the restaurant's net from its financial allocation (Module 36), what its refunds
 *                            actually returned (Module 35). Nothing is calculated that a domain did not already say.
 *   analytics_session_facts  one row per session — how far it came in the canonical funnel. Stages 1–8 are the
 *                            session's own behavioural events IN ORDER; stages 9–11 are read from the payment and
 *                            order domains through the checkout the session opened. No event a client sends can
 *                            move a session past stage 8.
 */
final class FactBuilder
{
    private const ACTIVE = ['WAITING_FOR_RESTAURANT', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP'];

    public function __construct(private readonly Pseudonymizer $pseudonyms, private readonly EventRegistry $registry) {}

    /**
     * Copies the orders that changed since an instant (null = every order). Returns the order ids written.
     */
    public function orders(?CarbonImmutable $since, CarbonImmutable $now): int
    {
        [$customerKey, $customerBinding] = $this->pseudonyms->sql('customer', 'o.customer_id');
        [$journeyKey, $journeyBinding] = $this->pseudonyms->sql('journey', "o.snapshot->'journey'->>'id'");
        $detour = "nullif(o.snapshot->'journey'->'detour'->>'duration_seconds', '')::numeric::int";
        $band = Bands::detourSql($detour);
        $seconds = fn (string $to, string $from): string => "case when {$to} is not null and {$from} is not null then greatest(0, extract(epoch from ({$to} - {$from})))::int end";
        $changed = $since === null ? '' : 'where o.id in (select id from orders where updated_at >= ? union select order_id from order_financial_allocations where updated_at >= ? union select order_id from refunds where updated_at >= ? and order_id is not null)';

        $columns = [
            'market_id', 'organization_id', 'location_id', 'currency', 'customer_key', 'status', 'refund_status', 'pickup_mode', 'pickup_method', 'rejection_reason', 'cancelled_by_type', 'restaurant_timezone',
            'placed_at', 'accepted_at', 'rejected_at', 'preparing_at', 'ready_at', 'picked_up_at', 'completed_at', 'cancelled_at', 'pickup_at',
            'subtotal_minor', 'discount_minor', 'tax_minor', 'fee_minor', 'total_minor', 'item_count', 'estimated_prep_minutes',
            'accept_seconds', 'prep_seconds', 'pickup_wait_seconds', 'total_seconds',
            'allocation_status', 'recognized_at', 'merchandise_gross_minor', 'commission_minor', 'restaurant_net_minor', 'refunded_minor', 'refunds_completed',
            'journey_key', 'detour_band', 'detour_seconds', 'corridors', 'source_updated_at', 'computed_at',
        ];

        $sql = 'insert into analytics_order_facts (order_id, '.implode(', ', $columns).")
            select o.id, o.market_id, o.organization_id, o.location_id, o.currency, {$customerKey}, o.status, o.refund_status, o.pickup_mode, o.pickup_method, o.rejection_reason, o.cancelled_by_type,
                coalesce(nullif(o.restaurant_timezone, ''), 'UTC'),
                coalesce(o.placed_at, o.created_at), o.accepted_at, o.rejected_at, o.preparing_at, o.ready_at, o.picked_up_at, o.completed_at, o.cancelled_at, o.pickup_at,
                o.subtotal_minor, o.discount_minor, o.tax_minor, o.fee_minor, o.total_minor, o.item_count, o.estimated_prep_minutes,
                {$seconds('o.accepted_at', 'coalesce(o.placed_at, o.created_at)')}, {$seconds('o.ready_at', 'o.accepted_at')}, {$seconds('o.picked_up_at', 'o.ready_at')}, {$seconds('o.completed_at', 'coalesce(o.placed_at, o.created_at)')},
                a.status, case when a.status = 'RECOGNIZED' then a.recognized_at end, case when a.status = 'RECOGNIZED' then a.merchandise_gross_minor end,
                case when a.status = 'RECOGNIZED' then a.commission_minor end, case when a.status = 'RECOGNIZED' then a.restaurant_net_minor end,
                coalesce(r.refunded, 0), coalesce(r.completed, 0),
                j.journey_key, {$band}, {$detour},
                coalesce((select se.properties->'corridors' from analytics_events se where se.event_name = 'journey_search_succeeded' and se.journey_key = j.journey_key limit 1), '[]'::jsonb),
                greatest(o.updated_at, coalesce(a.updated_at, o.updated_at), coalesce(r.updated, o.updated_at)), ?
            from orders o
            left join order_financial_allocations a on a.order_id = o.id
            left join lateral (
                select coalesce(sum(coalesce(approved_amount_minor, requested_amount_minor)) filter (where status = 'REFUNDED'), 0) as refunded, count(*) filter (where status = 'REFUNDED') as completed, max(updated_at) as updated
                from refunds where refunds.order_id = o.id
            ) r on true
            left join lateral (select case when nullif(o.snapshot->'journey'->>'id', '') is not null then {$journeyKey} end as journey_key) j on true
            {$changed}
            on conflict (order_id) do update set ".implode(', ', array_map(fn (string $c): string => "{$c} = excluded.{$c}", $columns));

        return DB::affectingStatement($sql, [...$customerBinding, $now, ...$journeyBinding, ...($since === null ? [] : [$since, $since, $since])]);
    }

    /**
     * Rebuilds the facts of the sessions that received an event since an instant (null = every session), and of the
     * sessions that opened a checkout recently — their payment or their order may have moved on.
     */
    public function sessions(?CarbonImmutable $since, CarbonImmutable $now): int
    {
        $recheck = $now->subDays((int) config('analytics.funnel.completion_window_days'));
        $ids = DB::table('analytics_events')->where('source', 'CLIENT')->whereNotNull('session_id')
            ->when($since !== null, fn ($q) => $q->where('received_at', '>=', $since))->distinct()->pluck('session_id')
            ->merge(DB::table('analytics_session_facts')->where('stage', '>=', 7)->where('stage', '<', 11)->where('first_event_at', '>=', $recheck)->pluck('session_id'))
            ->map(fn ($id): string => (string) $id)->unique()->values();

        $written = 0;
        foreach ($ids->chunk(300) as $chunk) {
            $written += $this->sessionChunk($chunk->all(), $now);
        }

        return $written;
    }

    /**
     * @param  list<string>  $sessionIds
     */
    private function sessionChunk(array $sessionIds, CarbonImmutable $now): int
    {
        $stageOf = array_flip($this->registry->stageEvents());
        $maxSeconds = (int) config('analytics.session.max_hours') * 3600;
        $sessions = [];
        $rows = DB::table('analytics_events')->where('source', 'CLIENT')->whereIn('session_id', $sessionIds)->orderBy('session_id')->orderBy('occurred_at')->orderBy('id')
            ->get(['session_id', 'event_name', 'occurred_at', 'platform', 'customer_key', 'market_id', 'checkout_ref']);
        foreach ($rows as $row) {
            $id = (string) $row->session_id;
            $at = CarbonImmutable::parse((string) $row->occurred_at)->utc();
            $s = &$sessions[$id];
            $s ??= ['first' => $at, 'last' => $at, 'events' => 0, 'stage' => 0, 'seen' => 0, 'out_of_order' => false, 'platform' => null, 'customer_key' => null, 'market_id' => null, 'checkouts' => [], 'checkout_at' => null];
            // A session has a bounded life: what the same identifier sends after it is not part of this session.
            if ($at->getTimestamp() - $s['first']->getTimestamp() > $maxSeconds) {
                unset($s);

                continue;
            }
            $s['last'] = $at;
            $s['events']++;
            $s['platform'] ??= $row->platform;
            $s['customer_key'] ??= $row->customer_key;
            $s['market_id'] ??= $row->market_id === null ? null : (int) $row->market_id;
            $stage = $stageOf[(string) $row->event_name] ?? null;
            if ($stage !== null) {
                $s['seen'] |= 1 << $stage;
                if ($stage === $s['stage'] + 1) {
                    $s['stage'] = $stage;
                } elseif ($stage > $s['stage'] + 1) {
                    // A later stage without the one before it: counted nowhere in the funnel, reported by data quality.
                    $s['out_of_order'] = true;
                }
                if ($stage === 7 && $row->checkout_ref !== null && $s['stage'] >= 7) {
                    $s['checkouts'][(string) $row->checkout_ref] = true;
                    $s['checkout_at'] ??= $at;
                }
            }
            unset($s);
        }
        if ($sessions === []) {
            return 0;
        }

        $refs = array_values(array_unique(array_merge(...array_map(fn (array $s): array => array_keys($s['checkouts']), array_values($sessions)))));
        $payments = $refs === [] ? collect() : DB::table('payment_attempts')->whereIn('checkout_public_id', $refs)->where('status', 'VERIFIED')->orderBy('verified_at')->get(['checkout_public_id', 'verified_at'])->groupBy('checkout_public_id');
        $orders = $refs === [] ? collect() : DB::table('orders')->whereIn('checkout_public_id', $refs)->get(['id', 'checkout_public_id', 'status', 'placed_at', 'created_at', 'completed_at'])->keyBy('checkout_public_id');
        $markets = DB::table('markets')->orderBy('id')->get(['id', 'default_timezone'])->keyBy('id');
        $fallback = $markets->first();
        $window = (int) config('analytics.funnel.conversion_window_hours') * 3600;

        $facts = [];
        foreach ($sessions as $id => $s) {
            $market = $s['market_id'] !== null && isset($markets[$s['market_id']]) ? $markets[$s['market_id']] : $fallback;
            $timezone = (string) ($market?->default_timezone ?: 'UTC');
            $fact = [
                'session_id' => $id, 'market_id' => $market?->id, 'platform' => $s['platform'], 'customer_key' => $s['customer_key'], 'first_event_at' => $s['first'], 'last_event_at' => $s['last'],
                'local_date' => $s['first']->setTimezone($timezone)->toDateString(), 'timezone' => $timezone, 'events_count' => $s['events'], 'stage' => $s['stage'], 'seen_stages' => $s['seen'],
                'out_of_order' => $s['out_of_order'], 'checkout_ref' => null, 'order_id' => null, 'payment_verified_at' => null, 'order_created_at' => null, 'order_completed_at' => null, 'computed_at' => $now,
            ];
            // Stages 9–11 belong to the payment and order domains — and only to a session that got to "payment started".
            if ($s['stage'] >= 8) {
                foreach (array_keys($s['checkouts']) as $ref) {
                    $verified = collect($payments[$ref] ?? [])->first(fn ($p): bool => $p->verified_at !== null && $s['checkout_at'] !== null
                        && CarbonImmutable::parse((string) $p->verified_at)->getTimestamp() - $s['checkout_at']->getTimestamp() <= $window);
                    if ($verified === null) {
                        continue;
                    }
                    $order = $orders[$ref] ?? null;
                    $reached = $order === null ? 9 : ($order->status === 'COMPLETED' ? 11 : 10);
                    // A session that opened several checkouts counts once, at the furthest any of them came.
                    if ($reached <= $fact['stage']) {
                        continue;
                    }
                    $fact['checkout_ref'] = $ref;
                    $fact['stage'] = $reached;
                    $fact['payment_verified_at'] = CarbonImmutable::parse((string) $verified->verified_at)->utc();
                    $fact['order_id'] = $order === null ? null : (int) $order->id;
                    $fact['order_created_at'] = $order === null ? null : CarbonImmutable::parse((string) ($order->placed_at ?? $order->created_at))->utc();
                    $fact['order_completed_at'] = $reached === 11 && $order->completed_at !== null ? CarbonImmutable::parse((string) $order->completed_at)->utc() : null;
                }
            }
            $fact['checkout_ref'] ??= array_key_first($s['checkouts']);
            $facts[] = $fact;
        }

        return DB::table('analytics_session_facts')->upsert($facts, ['session_id'], array_values(array_diff(array_keys($facts[0]), ['session_id'])));
    }

    /**
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return self::ACTIVE;
    }
}
