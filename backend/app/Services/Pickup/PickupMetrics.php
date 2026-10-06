<?php

namespace App\Services\Pickup;

use App\Enums\PickupSelectionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numbers about pickup availability for platform administrators: how often it was asked for, how often nothing
 * could be offered, how often ASAP was possible, how many slots were generated, how many choices were made,
 * refused or became invalid. Counts only — never a cart, a customer, a chosen time or a journey.
 *
 * Counters live in the cache in one-hour buckets and simply age out; the selection counts are read from the
 * database when asked.
 */
final class PickupMetrics
{
    private const PREFIX = 'pickup:metrics:';

    public const EVENTS = [
        'availability_requests', 'availability_blocked', 'no_slots', 'asap_available', 'slots_generated', 'journey_aware',
        'selected_asap', 'selected_scheduled', 'cleared', 'invalidated', 'refused', 'slot_full',
    ];

    public function record(string $event, int $by = 1): void
    {
        if ($by < 1 || ! in_array($event, self::EVENTS, true)) {
            return;
        }
        $key = self::PREFIX.$event.':'.now()->utc()->format('YmdH');
        Cache::add($key, 0, max(1, (int) config('pickup.metrics.retention_hours')) * 3600);
        Cache::increment($key, $by);
    }

    /**
     * One availability answer.
     */
    public function recordAvailability(PickupAvailability $availability): void
    {
        $this->record('availability_requests');
        if ($availability->isBlocked()) {
            $this->record($availability->kitchenReadyAt === null ? 'availability_blocked' : 'no_slots');
        }
        if ($availability->asap['available']) {
            $this->record('asap_available');
        }
        $this->record('slots_generated', count($availability->slots));
        if (($availability->journey['arrival_at'] ?? null) !== null) {
            $this->record('journey_aware');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $hours = 24): array
    {
        $hours = max(1, min($hours, (int) config('pickup.metrics.retention_hours')));
        $now = CarbonImmutable::now('UTC');
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = 0;
            for ($i = 0; $i < $hours; $i++) {
                $events[$event] += (int) Cache::get(self::PREFIX.$event.':'.$now->subHours($i)->format('YmdH'), 0);
            }
        }

        $byStatus = DB::table('cart_pickup_selections')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byMode = DB::table('cart_pickup_selections')->where('status', PickupSelectionStatus::Selected->value)->selectRaw('mode, count(*) as n')->groupBy('mode')->pluck('n', 'mode');
        $capacity = DB::table('pickup_slot_reservations')->where('status', 'ACTIVE')->where('slot_starts_at', '>=', $now)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->selectRaw('source, coalesce(sum(units), 0)::int as units')->groupBy('source')->pluck('units', 'source');

        $requests = max(1, $events['availability_requests']);

        return [
            'checked_at' => $now->toIso8601String(),
            'selections' => [
                'selected' => (int) ($byStatus[PickupSelectionStatus::Selected->value] ?? 0),
                'selected_asap' => (int) ($byMode['ASAP'] ?? 0),
                'selected_scheduled' => (int) ($byMode['SCHEDULED'] ?? 0),
                'stale' => (int) ($byStatus[PickupSelectionStatus::Stale->value] ?? 0),
                'invalid' => (int) ($byStatus[PickupSelectionStatus::Invalid->value] ?? 0),
                'expired' => (int) ($byStatus[PickupSelectionStatus::Expired->value] ?? 0),
                'converted' => (int) ($byStatus[PickupSelectionStatus::Converted->value] ?? 0),
            ],
            'window_hours' => $hours,
            'events' => $events,
            'rates' => [
                'no_slots' => $events['availability_requests'] === 0 ? null : round(($events['no_slots'] + $events['availability_blocked']) / $requests, 4),
                'asap_available' => $events['availability_requests'] === 0 ? null : round($events['asap_available'] / $requests, 4),
                'journey_aware' => $events['availability_requests'] === 0 ? null : round($events['journey_aware'] / $requests, 4),
            ],
            // Capacity units in upcoming slots by where they come from. Orders and checkout holds do not exist yet.
            'upcoming_capacity_units' => ['fixture' => (int) ($capacity['FIXTURE'] ?? 0), 'hold' => (int) ($capacity['HOLD'] ?? 0), 'order' => (int) ($capacity['ORDER'] ?? 0)],
            'slot_hold' => 'NOT_IMPLEMENTED',
        ];
    }
}
