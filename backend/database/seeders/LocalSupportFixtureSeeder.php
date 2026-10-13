<?php

namespace Database\Seeders;

use App\Models\Market;
use App\Models\SupportSlaPolicy;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT SUPPORT FIXTURE — local and testing only, never production.
 *
 * It seeds ONLY internal SLA targets, so that "at risk" and "breached" can be seen on this machine:
 *
 *     any case                 first reply within 4 h     resolved within 48 h
 *     HIGH priority            first reply within 1 h     resolved within 24 h
 *     URGENT priority          first reply within 30 min  resolved within 8 h
 *     the FINANCE queue        first reply within 8 h     resolved within 72 h
 *
 * EVERY ONE OF THEM IS A TEST FIXTURE (approval TEST). Nobody approved a response time, none is a commitment to a
 * customer or a restaurant, and none is shown to one. The clock stands still while support waits for the requester.
 *
 * NO SUPPORT CASE IS SEEDED: a case is something a person (or an operational condition) opened. The scenarios the
 * specification lists — a delayed order, a payment without an order, a pending refund, a settlement question — are
 * created through the real API by the tests and the end-to-end runs, with the real records they point at.
 *
 * Seeding again changes nothing: a fixture policy is found by its name.
 */
class LocalSupportFixtureSeeder extends Seeder
{
    public const POLICIES = [
        'TEST FIXTURE — any case (internal target, not a commitment)' => ['first_response_minutes' => 240, 'resolution_minutes' => 2880],
        'TEST FIXTURE — high priority (internal target, not a commitment)' => ['priority' => 'HIGH', 'first_response_minutes' => 60, 'resolution_minutes' => 1440],
        'TEST FIXTURE — urgent priority (internal target, not a commitment)' => ['priority' => 'URGENT', 'first_response_minutes' => 30, 'resolution_minutes' => 480],
        'TEST FIXTURE — finance queue (internal target, not a commitment)' => ['team' => 'FINANCE', 'first_response_minutes' => 480, 'resolution_minutes' => 4320],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $india = Market::query()->where('country_code', 'IN')->first();
        if ($india === null) {
            return;
        }
        foreach (self::POLICIES as $name => $attributes) {
            if (SupportSlaPolicy::query()->where('name', $name)->exists()) {
                continue;
            }
            (new SupportSlaPolicy)->forceFill(['name' => $name, 'market_id' => $india->getKey(), 'pause_while_waiting_for_requester' => true, 'status' => SupportSlaPolicy::ACTIVE, 'approval' => SupportSlaPolicy::TEST, ...$attributes])->save();
        }
    }
}
