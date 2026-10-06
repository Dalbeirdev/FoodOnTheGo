<?php

namespace App\Http\Resources\Pickup;

use App\Models\RestaurantLocationPickupMethod;
use App\Services\Pickup\PickupAvailability;
use App\Services\Pickup\PickupSelectionView;
use App\Services\Pickup\PickupSlot;
use Carbon\CarbonImmutable;

/**
 * Pickup availability and the pickup selection as clients see them (Module 28).
 *
 * Every time is an instant in UTC (ISO 8601) and, next to it, the same instant on the RESTAURANT's clock with its
 * UTC offset — so a client can show the restaurant's local time without knowing the rules of its time zone, and a
 * repeated hour on a clock change stays unambiguous. `local_date` is the restaurant-local calendar date to group
 * slots by. Labels ("Today", "7:20 PM") are the client's: nothing here is a display string.
 *
 * Customers get capacity as AVAILABLE / LIMITED / FULL only — never counts, never anything about other orders.
 */
final class PickupPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function availability(PickupAvailability $a, ?PickupSelectionView $selection): array
    {
        $location = $a->location;
        $arrival = $a->journey['arrival_at'] ?? null;

        return [
            'restaurant' => ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name, 'timezone' => $a->timezone],
            'timezone' => $a->timezone,
            'cart_version' => $a->cartVersion,
            'pickup_enabled' => $a->pickupEnabled,
            'can_select' => ! $a->isBlocked(),
            'methods' => array_map(fn (RestaurantLocationPickupMethod $m): array => [
                'method' => $m->method->value, 'instructions' => $m->instructions, 'requires_vehicle_info' => (bool) $m->requires_vehicle_info,
            ], $a->methods),
            'method' => $a->method?->value,
            'preparation' => $a->preparation?->forCustomers(),
            'earliest_pickup_at' => self::utc($a->earliestPickupAt()),
            'asap' => [
                'enabled' => $a->asap['enabled'],
                'available' => $a->asap['available'],
                'pickup_at' => self::utc($a->asap['pickup_at']),
                'local_pickup_at' => self::local($a->asap['pickup_at'], $a->timezone),
                'estimated_ready_in_minutes' => $a->asap['ready_in_minutes'],
                'recommended' => $a->asap['recommended'],
                'reason' => $a->asap['reason']?->value,
                'arrival_vs_pickup_delta_seconds' => self::delta($a->asap['pickup_at'], $arrival),
            ],
            'scheduled' => [
                'enabled' => $a->scheduled['enabled'],
                'interval_minutes' => $a->scheduled['interval_minutes'],
                'horizon_minutes' => $a->scheduled['horizon_minutes'],
                'horizon_ends_at' => self::utc($a->scheduled['horizon_ends_at']),
                'slot_count' => count($a->slots),
                'available_slot_count' => $a->availableSlotCount(),
                'truncated' => $a->scheduled['truncated'],
            ],
            'slots' => array_map(fn (PickupSlot $s): array => $this->slot($s, $a->timezone, $arrival), $a->slots),
            'recommended' => $a->recommended === null ? null : [
                'mode' => $a->recommended['mode']->value,
                'slot_key' => $a->recommended['slot_key'],
                'pickup_at' => self::utc($a->recommended['pickup_at']),
                'local_pickup_at' => self::local($a->recommended['pickup_at'], $a->timezone),
                // ARRIVAL: chosen for when the traveller is expected. KITCHEN: the earliest the order can be ready.
                'basis' => $a->recommended['basis'],
                'arrival_vs_pickup_delta_seconds' => self::delta($a->recommended['pickup_at'], $arrival),
            ],
            'journey' => $a->journey === null ? null : [
                'status' => $a->journey['status'],
                'traveler_arrival_at' => self::utc($arrival),
                'local_traveler_arrival_at' => self::local($arrival, $a->timezone),
                'requires_refresh' => $a->journey['requires_refresh'],
                'estimate_source' => $a->journey['source'],
                'arrival_buffer_minutes' => max(0, (int) config('pickup.arrival_buffer_minutes')),
            ],
            'selection' => $this->selection($selection),
            'issues' => self::issues($a->issues),
            'generated_at' => self::utc($a->generatedAt),
            'revalidate_after' => self::utc($a->revalidateAfter),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selection(?PickupSelectionView $view): ?array
    {
        if ($view === null) {
            return null;
        }
        $s = $view->selection;
        $timezone = (string) $s->restaurant_timezone;
        $arrival = $view->availability->journey['arrival_at'] ?? null;

        return [
            'id' => $s->public_id,
            'mode' => $s->mode->value,
            'method' => $s->pickup_method->value,
            // SCHEDULED: the start of the chosen slot. ASAP: the earliest pickup as estimated now — it moves with the clock.
            'pickup_at' => self::utc($view->pickupAt),
            'local_pickup_at' => self::local($view->pickupAt, $timezone),
            'local_date' => $view->pickupAt->setTimezone($timezone)->format('Y-m-d'),
            'slot_key' => $s->slot_key,
            'slot_ends_at' => self::utc($s->slot_ends_at === null ? null : CarbonImmutable::instance($s->slot_ends_at)),
            'timezone' => $timezone,
            'cart_version' => (int) $s->cart_version,
            'status' => $view->status->value,
            'valid' => $view->valid(),
            // A selection is a choice. Nothing is held for it until checkout and order creation reserve the time.
            'reserved' => false,
            'issues' => self::issues($view->issues),
            'estimated_prep_minutes' => (int) $s->estimated_prep_minutes,
            'traveler_arrival_at' => self::utc($arrival),
            'arrival_vs_pickup_delta_seconds' => self::delta($view->pickupAt, $arrival),
            'selected_at' => self::utc(CarbonImmutable::instance($s->updated_at ?? $s->validated_at)),
            'validated_at' => self::utc(CarbonImmutable::instance($s->validated_at)),
            'expires_at' => self::utc(CarbonImmutable::instance($s->expires_at)),
            'revalidate_after' => self::utc($view->availability->revalidateAfter),
            'version' => (int) $s->version,
        ];
    }

    /**
     * What the restaurant's own staff see on the settings page: the same engine, summarised — plus the parts of the
     * preparation estimate and warnings about a configuration that offers customers nothing.
     *
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array<string, mixed>
     */
    public function preview(PickupAvailability $a, array $warnings): array
    {
        $days = [];
        foreach ($a->slots as $slot) {
            $date = $slot->startsAt->setTimezone($a->timezone)->format('Y-m-d');
            $days[$date] ??= ['local_date' => $date, 'slots' => 0, 'available' => 0, 'first_slot_at' => self::utc($slot->startsAt), 'last_slot_at' => null];
            $days[$date]['slots']++;
            $days[$date]['available'] += $slot->available ? 1 : 0;
            $days[$date]['last_slot_at'] = self::utc($slot->startsAt);
        }

        return [
            'timezone' => $a->timezone,
            'generated_at' => self::utc($a->generatedAt),
            'pickup_enabled' => $a->pickupEnabled,
            'customers_can_order' => ! $a->isBlocked(),
            'preparation' => $a->preparation?->forStaff(),
            'earliest_pickup_at' => self::utc($a->earliestPickupAt()),
            'asap' => ['enabled' => $a->asap['enabled'], 'available' => $a->asap['available'], 'pickup_at' => self::utc($a->asap['pickup_at']), 'estimated_ready_in_minutes' => $a->asap['ready_in_minutes'], 'reason' => $a->asap['reason']?->value],
            'scheduled' => [
                'enabled' => $a->scheduled['enabled'],
                'interval_minutes' => $a->scheduled['interval_minutes'],
                'horizon_minutes' => $a->scheduled['horizon_minutes'],
                'slot_count' => count($a->slots),
                'available_slot_count' => $a->availableSlotCount(),
                'truncated' => $a->scheduled['truncated'],
                'days' => array_values($days),
            ],
            'capacity_per_slot' => $a->location->pickupSettings?->capacity_per_slot,
            'capacity_unit' => 'ORDERS',
            'issues' => self::issues($a->issues),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(PickupSlot $slot, string $timezone, ?CarbonImmutable $arrival): array
    {
        return [
            'slot_key' => $slot->key,
            'starts_at' => self::utc($slot->startsAt),
            'ends_at' => self::utc($slot->endsAt),
            'local_starts_at' => self::local($slot->startsAt, $timezone),
            'local_date' => $slot->startsAt->setTimezone($timezone)->format('Y-m-d'),
            'available' => $slot->available,
            'capacity_state' => $slot->capacity->value,
            'recommended' => $slot->recommended,
            'reason' => $slot->reason?->value,
            'arrival_vs_pickup_delta_seconds' => self::delta($slot->startsAt, $arrival),
        ];
    }

    /**
     * Issues as JSON: `details` is always an object, also when it is empty.
     *
     * @param  list<array{code: string, severity: string, message: string, details: array<string, mixed>}>  $issues
     * @return list<array<string, mixed>>
     */
    private static function issues(array $issues): array
    {
        return array_map(fn (array $issue): array => [...$issue, 'details' => (object) $issue['details']], $issues);
    }

    private static function utc(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }

    private static function local(?CarbonImmutable $instant, string $timezone): ?string
    {
        return $instant?->setTimezone($timezone)->toIso8601String();
    }

    /**
     * Seconds between the traveller's estimated arrival and the pickup time: positive = the traveller is there
     * first and waits, negative = the order may be ready before they arrive.
     */
    private static function delta(?CarbonImmutable $pickupAt, ?CarbonImmutable $arrival): ?int
    {
        return $pickupAt === null || $arrival === null ? null : $pickupAt->getTimestamp() - $arrival->getTimestamp();
    }
}
