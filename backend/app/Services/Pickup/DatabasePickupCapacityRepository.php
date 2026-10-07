<?php

namespace App\Services\Pickup;

use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Capacity kept in PostgreSQL (pickup_slot_reservations). The database is the truth about capacity: a cache could
 * lose a reservation and oversell a slot.
 *
 * `reserve` serializes the callers of ONE slot of ONE location with a transaction-scoped advisory lock, counts
 * what is in use and inserts only when there is room — so the last unit can never be given to two callers.
 * A hold that has run out (expires_at) no longer counts and needs no cleanup to be ignored.
 */
final class DatabasePickupCapacityRepository implements PickupCapacityRepository
{
    public function usage(RestaurantLocation $location, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $at = null, ?int $exceptCartId = null): array
    {
        $now = CarbonImmutable::instance($at ?? now())->utc();
        $rows = DB::table('pickup_slot_reservations')
            ->where('location_id', $location->getKey())
            ->where('status', PickupSlotReservation::STATUS_ACTIVE)
            ->where('slot_starts_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('slot_starts_at', '<', CarbonImmutable::instance($to)->utc())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            // The place a cart's own checkout holds (Module 29) is not taken from that cart: its owner must still be
            // offered the time they are holding.
            ->when($exceptCartId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('checkout_session_id')->orWhereNotIn('checkout_session_id', DB::table('checkout_sessions')->select('id')->where('cart_id', $exceptCartId))))
            ->groupBy('slot_starts_at')
            ->selectRaw('extract(epoch from slot_starts_at)::bigint as starts, sum(units)::int as units')
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row->starts] = (int) $row->units;
        }

        return $usage;
    }

    public function reserve(
        RestaurantLocation $location,
        CarbonInterface $slotStart,
        CarbonInterface $slotEnd,
        ?int $capacity,
        int $units,
        string $source,
        ?string $reference = null,
        ?CarbonInterface $expiresAt = null,
    ): ?PickupSlotReservation {
        $start = CarbonImmutable::instance($slotStart)->utc();
        $end = CarbonImmutable::instance($slotEnd)->utc();

        return DB::transaction(function () use ($location, $start, $end, $capacity, $units, $source, $reference, $expiresAt): ?PickupSlotReservation {
            // One slot of one location at a time: the count below and the insert are then one step for everybody else.
            DB::select('select pg_advisory_xact_lock(?, ?)', [(int) $location->getKey(), intdiv($start->getTimestamp(), 60)]);

            if ($capacity !== null) {
                $used = array_sum($this->usage($location, $start, $end));
                if ($used + $units > $capacity) {
                    return null;
                }
            }

            $reservation = (new PickupSlotReservation)->forceFill([
                'location_id' => $location->getKey(),
                'slot_starts_at' => $start,
                'units' => $units,
                'source' => $source,
                'reference' => $reference,
                'status' => PickupSlotReservation::STATUS_ACTIVE,
                'expires_at' => $expiresAt === null ? null : CarbonImmutable::instance($expiresAt)->utc(),
            ]);
            $reservation->save();

            return $reservation;
        });
    }

    public function release(PickupSlotReservation $reservation): void
    {
        $reservation->forceFill(['status' => PickupSlotReservation::STATUS_RELEASED])->save();
    }
}
