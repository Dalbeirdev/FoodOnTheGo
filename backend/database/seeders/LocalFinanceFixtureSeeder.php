<?php

namespace Database\Seeders;

use App\Models\CommissionPlan;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayoutProfile;
use App\Services\Finance\Payout\FakePayoutProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT FINANCE FIXTURE — local and testing only, never production.
 *
 * EVERYTHING HERE IS A TEST FIXTURE. NOTHING IS A COMMERCIAL TERM OF FOODONTHEGO.
 *
 *   commission plans (approval TEST — never applied in production):
 *     India market           10.00 %  of merchandise net of the restaurant's own discounts   from 1 Jan 2026
 *     Brew & Bites           8.00 %   of the merchandise as priced                           from 1 Jan 2026
 *                            (a location plan: shows that negotiated terms override the market's)
 *   The 10 % is the specification's example for a development test. No commission was approved by the business; the
 *   production rate, its base and whether a restaurant's terms may differ are PENDING BUSINESS APPROVAL.
 *
 *   payout destinations at the FAKE payout provider (a token and a mask — no bank detail, no KYC):
 *     Burger Hub, Brew & Bites, Healthy Bites, Pizza Point            VERIFIED, "•••• 4321" …
 *   They exist so that a payout can be tried on this machine. Restaurant KYC / bank onboarding with a real provider
 *   does not exist; LIVE PAYOUT = DISABLED.
 *
 * NO TAX RULE, NO FEE RULE, NO REFUND-LIABILITY POLICY AND NO SETTLEMENT SCHEDULE IS SEEDED: those are decisions
 * nobody has made, and a value written here would be an invented one.
 *
 * Seeding again changes nothing: a fixture plan is found by its name and scope, a profile by its location.
 */
class LocalFinanceFixtureSeeder extends Seeder
{
    public const MARKET_PLAN = 'TEST FIXTURE — India market (not a production commission)';

    public const LOCATION_PLAN = 'TEST FIXTURE — Brew & Bites negotiated terms (not a production commission)';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $india = Market::query()->where('country_code', 'IN')->first();
        if ($india === null) {
            return;
        }
        $from = CarbonImmutable::parse('2026-01-01 00:00', (string) ($india->default_timezone ?: 'UTC'));
        $plan = function (string $name, array $attributes) use ($india, $from): void {
            if (CommissionPlan::query()->where('name', $name)->where('market_id', $india->getKey())->exists()) {
                return;
            }
            (new CommissionPlan)->forceFill([
                'name' => $name, 'market_id' => $india->getKey(), 'type' => 'PERCENTAGE', 'effective_from' => $from,
                'approval' => CommissionPlan::APPROVAL_TEST, 'source' => CommissionPlan::SOURCE_FIXTURE,
                'reason' => 'TEST FIXTURE for local development. Not a commercial term: no commission was approved by the business.',
                ...$attributes,
            ])->save();
        };
        $plan(self::MARKET_PLAN, ['scope_type' => CommissionPlan::SCOPE_MARKET, 'rate_bps' => 1000, 'base_policy' => 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT']);
        $brewBites = RestaurantLocation::query()->where('slug', 'brew-bites')->first();
        if ($brewBites !== null) {
            $plan(self::LOCATION_PLAN, ['scope_type' => CommissionPlan::SCOPE_LOCATION, 'organization_id' => $brewBites->organization_id, 'location_id' => $brewBites->getKey(), 'rate_bps' => 800, 'base_policy' => 'MERCHANDISE_GROSS']);
        }

        foreach (['burger-hub' => '4321', 'brew-bites' => '7788', 'healthy-bites' => '1056', 'pizza-point' => '9902'] as $slug => $last) {
            $location = RestaurantLocation::query()->where('slug', $slug)->first();
            if ($location === null || RestaurantPayoutProfile::query()->where('location_id', $location->getKey())->where('provider', FakePayoutProvider::NAME)->exists()) {
                continue;
            }
            (new RestaurantPayoutProfile)->forceFill([
                'organization_id' => $location->organization_id, 'location_id' => $location->getKey(), 'provider' => FakePayoutProvider::NAME,
                // The fake provider treats every reference it issued ("fa_…") as an active test account.
                'provider_account_reference' => 'fa_fixture_'.str_replace('-', '', $slug), 'destination_mask' => '•••• '.$last,
                'status' => RestaurantPayoutProfile::VERIFIED, 'provider_status' => 'activated', 'verified_at' => now(), 'checked_at' => now(),
            ])->save();
        }
    }
}
