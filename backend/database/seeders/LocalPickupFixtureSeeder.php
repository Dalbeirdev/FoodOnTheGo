<?php

namespace Database\Seeders;

use App\Models\PickupSlotReservation;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPickupSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT PICKUP FIXTURE — local and testing only, never production.
 *
 * The restaurant fixtures (Module 23) already cover most pickup situations by themselves:
 *
 *   ASAP + scheduled, open ............ Burger Hub and most others
 *   not accepting orders (paused) ..... Hoshiarpur Sweets & Snacks
 *   temporarily closed ................ Wok Express
 *   overnight hours (23:00–04:00) ..... Night Owl Kitchen
 *   special closure / special hours ... Ambala Chai Point, Rajwada Thali House
 *
 * This seeder adds the one thing they lack — a restaurant with a CAPACITY LIMIT and slots that are already used:
 *
 *   Pizza Point   capacity 2 orders per slot; today and tomorrow (restaurant-local dates)
 *                 13:00 and 19:00 are full (2 of 2), 13:15 and 19:30 have one place left (LIMITED)
 *
 * The used capacity is written as FIXTURE records (pickup_slot_reservations.source): they are not orders and are
 * never presented as orders. Seeding again replaces them, so the dates stay "today and tomorrow"; seeding twice
 * on the same day changes nothing. ASAP-only, scheduled-only, pickup-disabled and split-hours restaurants are
 * built by the automated tests (tests/Support/BuildsPickup) on a fixed clock rather than seeded here, because
 * the earlier modules' review flows rely on the seeded restaurants as they are.
 */
class LocalPickupFixtureSeeder extends Seeder
{
    public const REFERENCE = 'local-pickup-fixture';

    /** Restaurant-local time => units used (capacity is 2). */
    private const USED = ['13:00' => 2, '13:15' => 1, '19:00' => 2, '19:30' => 1];

    public function run(): void
    {
        $pizzaPoint = RestaurantLocation::query()->where('slug', 'pizza-point')->first();
        $settings = $pizzaPoint === null ? null : RestaurantPickupSettings::query()->where('location_id', $pizzaPoint->getKey())->first();
        if ($pizzaPoint === null || $settings === null) {
            return;
        }

        if ($settings->capacity_per_slot === null) {
            $settings->forceFill(['capacity_per_slot' => 2, 'version' => (int) $settings->version + 1])->save();
        }

        PickupSlotReservation::query()->where('location_id', $pizzaPoint->getKey())->where('source', PickupSlotReservation::SOURCE_FIXTURE)->where('reference', self::REFERENCE)->delete();
        $today = CarbonImmutable::now((string) $pizzaPoint->timezone)->startOfDay();
        foreach ([$today, $today->addDay()] as $day) {
            foreach (self::USED as $time => $units) {
                (new PickupSlotReservation)->forceFill([
                    'location_id' => $pizzaPoint->getKey(),
                    'slot_starts_at' => $day->setTimeFromTimeString($time)->utc(),
                    'units' => $units,
                    'source' => PickupSlotReservation::SOURCE_FIXTURE,
                    'reference' => self::REFERENCE,
                    'status' => PickupSlotReservation::STATUS_ACTIVE,
                ])->save();
            }
        }
    }
}
