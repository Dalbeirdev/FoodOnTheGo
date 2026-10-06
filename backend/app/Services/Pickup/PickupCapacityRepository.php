<?php

namespace App\Services\Pickup;

use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use Carbon\CarbonInterface;

/**
 * Where the pickup engine learns how much of a slot's capacity is used — and the only way capacity is taken.
 *
 * The unit is ORDERS per slot. Today the rows are fixtures and test records; the orders module (Module 31) will
 * count confirmed and in-progress orders, and checkout (Module 29) may hold a slot for a short time. Both will go
 * through this contract, so the engine does not change when they arrive.
 */
interface PickupCapacityRepository
{
    /**
     * Units in use per reserved slot start within [$from, $to), in one aggregated query.
     *
     * @return array<int, int> unix timestamp of the slot start => units
     */
    public function usage(RestaurantLocation $location, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $at = null): array;

    /**
     * Takes $units of the slot [$slotStart, $slotEnd) — atomically: of two callers competing for the last unit
     * exactly one gets a reservation, the other gets null. `$capacity` null means the slot has no limit.
     */
    public function reserve(
        RestaurantLocation $location,
        CarbonInterface $slotStart,
        CarbonInterface $slotEnd,
        ?int $capacity,
        int $units,
        string $source,
        ?string $reference = null,
        ?CarbonInterface $expiresAt = null,
    ): ?PickupSlotReservation;

    public function release(PickupSlotReservation $reservation): void;
}
