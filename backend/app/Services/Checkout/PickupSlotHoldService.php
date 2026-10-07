<?php

namespace App\Services\Checkout;

use App\Enums\PickupMode;
use App\Models\CheckoutSession;
use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use App\Services\Pickup\PickupCapacityRepository;
use App\Services\Pickup\PickupSlotGenerator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The pickup time held for a confirmed checkout (Module 29) — the hold Module 28 deliberately left out.
 *
 * A pickup SELECTION holds nothing. When a checkout is confirmed (READY) one capacity unit — one order — is held in
 * the slot of the chosen time, so that the place cannot disappear while the customer pays:
 *
 *  - it is a row of pickup_slot_reservations with source HOLD, bound to its checkout, with the SAME expiry as the
 *    checkout (a checkout never outlives its hold);
 *  - it is taken atomically: the count and the insert run in one transaction under a PostgreSQL advisory lock per
 *    restaurant and slot (PickupCapacityRepository::reserve) — of two customers competing for the last place exactly
 *    one gets it;
 *  - it counts against capacity only while it is ACTIVE and not run out — a run-out hold frees its place at that
 *    instant, without waiting for the clean-up;
 *  - it is released when the checkout is cancelled, replaced, goes stale or expires; an order consumes it (Module 31).
 *
 * An ASAP pickup holds the slot its pickup time falls in (the same rule Module 28 uses to count ASAP orders).
 * PostgreSQL is the authority: Redis is not involved in capacity.
 */
final class PickupSlotHoldService
{
    public function __construct(private readonly PickupCapacityRepository $capacity) {}

    /**
     * The slot a checkout's pickup time uses: [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function slotOf(PickupMode $mode, CarbonInterface $pickupAt, ?CarbonInterface $slotEndsAt, int $intervalMinutes, string $timezone): array
    {
        $at = CarbonImmutable::instance($pickupAt);
        if ($mode === PickupMode::Scheduled && $slotEndsAt !== null) {
            return [$at->utc(), CarbonImmutable::instance($slotEndsAt)->utc()];
        }
        $start = PickupSlotGenerator::alignDown($at, max(1, $intervalMinutes), $timezone);

        return [$start->utc(), $start->addMinutes(max(1, $intervalMinutes))->utc()];
    }

    /**
     * Holds the checkout's slot until `$expiresAt`. A hold the checkout already has for the same slot is kept and
     * given the new expiry. Returns null when the slot has no place left.
     */
    public function hold(CheckoutSession $session, RestaurantLocation $location, CarbonInterface $expiresAt): ?PickupSlotReservation
    {
        $start = CarbonImmutable::instance($session->pickup_slot_starts_at)->utc();
        $end = CarbonImmutable::instance($session->pickup_slot_ends_at)->utc();
        $existing = $this->active($session);
        if ($existing !== null && CarbonImmutable::instance($existing->slot_starts_at)->equalTo($start)) {
            $existing->forceFill(['expires_at' => CarbonImmutable::instance($expiresAt)->utc()])->save();

            return $existing;
        }
        if ($existing !== null) {
            $this->capacity->release($existing);
        }

        $hold = $this->capacity->reserve(
            $location,
            $start,
            $end,
            $location->pickupSettings?->capacity_per_slot,
            1,
            PickupSlotReservation::SOURCE_HOLD,
            (string) $session->public_id,
            $expiresAt,
        );
        $hold?->forceFill(['checkout_session_id' => $session->getKey()])->save();

        return $hold;
    }

    public function active(CheckoutSession $session, ?CarbonInterface $at = null): ?PickupSlotReservation
    {
        $at = CarbonImmutable::instance($at ?? now());

        return PickupSlotReservation::query()
            ->where('checkout_session_id', $session->getKey())
            ->where('status', PickupSlotReservation::STATUS_ACTIVE)
            ->where('source', PickupSlotReservation::SOURCE_HOLD)
            ->where('expires_at', '>', $at)
            ->first();
    }

    /**
     * Gives the place back. Safe to repeat.
     */
    public function release(CheckoutSession $session): int
    {
        return PickupSlotReservation::query()
            ->where('checkout_session_id', $session->getKey())
            ->where('status', PickupSlotReservation::STATUS_ACTIVE)
            ->where('source', PickupSlotReservation::SOURCE_HOLD)
            ->update(['status' => PickupSlotReservation::STATUS_RELEASED, 'updated_at' => now()]);
    }

    /**
     * Marks the holds whose time ran out (they stopped counting when it did). Returns how many.
     */
    public function expireRunOut(?CarbonInterface $at = null): int
    {
        $at = CarbonImmutable::instance($at ?? now());

        return PickupSlotReservation::query()
            ->where('status', PickupSlotReservation::STATUS_ACTIVE)
            ->where('source', PickupSlotReservation::SOURCE_HOLD)
            ->where('expires_at', '<=', $at)
            ->update(['status' => PickupSlotReservation::STATUS_EXPIRED, 'updated_at' => $at]);
    }

    /**
     * For Module 31: the order takes the place the checkout held — the hold becomes the order's reservation.
     * Returns null when the hold has run out (the order must then reserve the slot again, atomically).
     */
    public function consume(CheckoutSession $session, string $orderReference, ?CarbonInterface $at = null): ?PickupSlotReservation
    {
        $hold = $this->active($session, $at);
        $hold?->forceFill(['source' => PickupSlotReservation::SOURCE_ORDER, 'reference' => $orderReference, 'expires_at' => null])->save();

        return $hold;
    }
}
