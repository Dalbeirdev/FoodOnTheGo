<?php

namespace Database\Seeders;

use App\Models\Market;
use App\Models\Promotion;
use App\Models\RestaurantLocation;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT CHECKOUT FIXTURE — local and testing only, never production.
 *
 * PROMOTIONS for trying checkout by hand. The first six carry the codes the earlier mock checkout used, so the codes
 * a reviewer already knows keep working on the real backend; the rest put one promotion in every state:
 *
 *   WELCOME10    10 % off, everywhere                                   ACTIVE
 *   TRAVEL5      5 % off from ₹20                                        ACTIVE
 *   INDIA50      ₹50 off from ₹300, India                                ACTIVE
 *   BURGER20     20 % off at Burger Hub, at most ₹100                    ACTIVE
 *   NEWBIE       15 % off, once per customer                             ACTIVE
 *   EXPIRED      20 % off, ended 31 Dec 2025                             ACTIVE in the table → EXPIRED by the clock
 *   PIZZA20      20 % off at Pizza Point, at most ₹200                   ACTIVE (not applicable at other restaurants)
 *   BIG250       ₹250 off from ₹1,500                                    ACTIVE (minimum spend)
 *   FREEMEAL     100 % off — a total of zero, no payment needed          ACTIVE
 *   SOLDOUT      ₹100 off, 1 use, already used                           ACTIVE (limit reached)
 *   COMINGSOON   ₹100 off, starts 1 Jan 2030                             ACTIVE in the table → SCHEDULED by the clock
 *   PAUSED25     25 % off                                                PAUSED
 *   DRAFT75      ₹75 off                                                 DRAFT
 *   OLDCODE      ₹30 off                                                 DISABLED
 *
 * (NEWBIE was "new customers only" in the mock. That eligibility rule is not an approved promotion type; the
 * backend's nearest rule — once per customer — is what the fixture uses.)
 *
 * NO TAX RULE AND NO FEE RULE IS SEEDED. A tax or a fee exists only when an administrator configures it for a
 * market, and a rate written here would be an invented rate. India's tax configuration is NOT CONFIGURED and stays
 * a production blocker until approved rules are supplied; the automated tests create their own rules and name them
 * TEST TAX CONFIGURATION.
 *
 * Seeding again puts every fixture promotion back into the state above (their codes are the key).
 */
class LocalCheckoutFixtureSeeder extends Seeder
{
    public const CODES = ['WELCOME10', 'TRAVEL5', 'INDIA50', 'BURGER20', 'NEWBIE', 'EXPIRED', 'PIZZA20', 'BIG250', 'FREEMEAL', 'SOLDOUT', 'COMINGSOON', 'PAUSED25', 'DRAFT75', 'OLDCODE'];

    public function run(): void
    {
        $india = Market::query()->where('country_code', 'IN')->first();
        if ($india === null) {
            return;
        }
        $currency = (string) $india->default_currency;
        $burgerHub = RestaurantLocation::query()->where('slug', 'burger-hub')->first();
        $pizzaPoint = RestaurantLocation::query()->where('slug', 'pizza-point')->first();
        $percent = fn (int $basisPoints, array $more = []): array => ['type' => 'PERCENTAGE', 'percent_basis_points' => $basisPoints, 'amount_minor' => null, ...$more];
        $fixed = fn (int $amountMinor, array $more = []): array => ['type' => 'FIXED_AMOUNT', 'amount_minor' => $amountMinor, 'percent_basis_points' => null, 'currency' => $currency, ...$more];
        $at = fn (?RestaurantLocation $location): array => ['scope' => 'RESTAURANT_LOCATION', 'market_id' => $location?->market_id, 'organization_id' => $location?->organization_id, 'location_id' => $location?->getKey()];

        $fixtures = [
            'WELCOME10' => ['name' => '10 % off your order', ...$percent(1000), 'starts_at' => '2026-01-01T00:00:00Z'],
            'TRAVEL5' => ['name' => '5 % off for travellers', ...$percent(500, ['currency' => $currency, 'minimum_spend_minor' => 2000]), 'starts_at' => '2026-01-01T00:00:00Z'],
            'INDIA50' => ['name' => '₹50 off from ₹300', ...$fixed(5000, ['minimum_spend_minor' => 30000]), 'scope' => 'MARKET', 'market_id' => $india->getKey(), 'starts_at' => '2026-01-01T00:00:00Z'],
            'BURGER20' => $burgerHub === null ? null : ['name' => '20 % off at Burger Hub', ...$percent(2000, ['currency' => $currency, 'maximum_discount_minor' => 10000]), ...$at($burgerHub), 'starts_at' => '2026-01-01T00:00:00Z'],
            'NEWBIE' => ['name' => '15 % off, once per customer', ...$percent(1500), 'per_customer_limit' => 1, 'starts_at' => '2026-01-01T00:00:00Z'],
            'EXPIRED' => ['name' => '20 % off (ended)', ...$percent(2000), 'starts_at' => '2025-01-01T00:00:00Z', 'ends_at' => '2025-12-31T23:59:59Z'],
            'PIZZA20' => $pizzaPoint === null ? null : ['name' => '20 % off at Pizza Point', ...$percent(2000, ['currency' => $currency, 'maximum_discount_minor' => 20000]), ...$at($pizzaPoint)],
            'BIG250' => ['name' => '₹250 off from ₹1,500', ...$fixed(25000, ['minimum_spend_minor' => 150000])],
            'FREEMEAL' => ['name' => 'TEST — 100 % off (a total of zero)', ...$percent(10000)],
            'SOLDOUT' => ['name' => '₹100 off (all used)', ...$fixed(10000), 'usage_limit' => 1, 'redeemed_count' => 1],
            'COMINGSOON' => ['name' => '₹100 off (not started)', ...$fixed(10000), 'starts_at' => '2030-01-01T00:00:00Z'],
            'PAUSED25' => ['name' => '25 % off (paused)', ...$percent(2500), 'status' => 'PAUSED'],
            'DRAFT75' => ['name' => '₹75 off (draft)', ...$fixed(7500), 'status' => 'DRAFT'],
            'OLDCODE' => ['name' => '₹30 off (disabled)', ...$fixed(3000), 'status' => 'DISABLED'],
        ];

        foreach ($fixtures as $code => $attributes) {
            if ($attributes === null) {
                continue;
            }
            $promotion = Promotion::query()->where('code', $code)->first() ?? new Promotion;
            $promotion->forceFill([
                'code' => $code,
                'description' => 'Development fixture.',
                'status' => 'ACTIVE', 'scope' => 'PLATFORM', 'market_id' => null, 'organization_id' => null, 'location_id' => null,
                'currency' => null, 'maximum_discount_minor' => null, 'minimum_spend_minor' => null, 'starts_at' => null, 'ends_at' => null,
                'usage_limit' => null, 'per_customer_limit' => null, 'redeemed_count' => 0, 'funding_source' => 'PLATFORM',
                ...$attributes,
            ])->save();
        }
    }
}
