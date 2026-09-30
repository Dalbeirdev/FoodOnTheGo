<?php

namespace Database\Seeders;

use App\Enums\RegionStatus;
use App\Enums\RegionType;
use App\Models\Market;
use App\Models\MarketConfiguration;
use App\Models\MarketRegion;
use App\Services\Market\MarketContext;
use Illuminate\Database\Seeder;

/**
 * Reference data (safe for every environment): India's states and union territories (ISO 3166-2:IN), the
 * market's coarse bounds and its starting configuration.
 *
 * Every region is created PLANNED — a row here is geography, not a statement that FoodOnTheGo operates
 * there. Re-running adds what is missing and never overwrites an administrator's later changes.
 */
class IndiaGeographySeeder extends Seeder
{
    /** @var array<string, string> ISO 3166-2 code => name */
    public const STATES = [
        'IN-AP' => 'Andhra Pradesh', 'IN-AR' => 'Arunachal Pradesh', 'IN-AS' => 'Assam', 'IN-BR' => 'Bihar', 'IN-CG' => 'Chhattisgarh',
        'IN-GA' => 'Goa', 'IN-GJ' => 'Gujarat', 'IN-HR' => 'Haryana', 'IN-HP' => 'Himachal Pradesh', 'IN-JH' => 'Jharkhand',
        'IN-KA' => 'Karnataka', 'IN-KL' => 'Kerala', 'IN-MP' => 'Madhya Pradesh', 'IN-MH' => 'Maharashtra', 'IN-MN' => 'Manipur',
        'IN-ML' => 'Meghalaya', 'IN-MZ' => 'Mizoram', 'IN-NL' => 'Nagaland', 'IN-OD' => 'Odisha', 'IN-PB' => 'Punjab',
        'IN-RJ' => 'Rajasthan', 'IN-SK' => 'Sikkim', 'IN-TN' => 'Tamil Nadu', 'IN-TS' => 'Telangana', 'IN-TR' => 'Tripura',
        'IN-UP' => 'Uttar Pradesh', 'IN-UK' => 'Uttarakhand', 'IN-WB' => 'West Bengal',
    ];

    /** @var array<string, string> */
    public const UNION_TERRITORIES = [
        'IN-AN' => 'Andaman and Nicobar Islands', 'IN-CH' => 'Chandigarh', 'IN-DH' => 'Dadra and Nagar Haveli and Daman and Diu', 'IN-DL' => 'Delhi',
        'IN-JK' => 'Jammu and Kashmir', 'IN-LA' => 'Ladakh', 'IN-LD' => 'Lakshadweep', 'IN-PY' => 'Puducherry',
    ];

    public function run(MarketContext $markets): void
    {
        $india = Market::query()->where('country_code', 'IN')->first();
        if ($india === null) {
            return;
        }

        $india->setBounds(...Market::INDIA_BOUNDS);

        foreach ([[self::STATES, RegionType::State], [self::UNION_TERRITORIES, RegionType::UnionTerritory]] as [$regions, $type]) {
            foreach ($regions as $code => $name) {
                if (! MarketRegion::query()->where('market_id', $india->id)->where('code', $code)->exists()) {
                    (new MarketRegion)->forceFill(['market_id' => $india->id, 'code' => $code, 'name' => $name, 'type' => $type, 'status' => RegionStatus::Planned])->save();
                }
            }
        }

        if (! MarketConfiguration::query()->where('market_id', $india->id)->exists()) {
            (new MarketConfiguration)->forceFill([
                'market_id' => $india->id,
                // Method availability only. Provider credentials never live in the database or reach a client.
                'payment' => [
                    'provider_strategy' => 'resolved per market by the backend',
                    'methods' => ['upi' => 'PLANNED', 'card' => 'PLANNED', 'netbanking' => 'PLANNED', 'wallet' => 'PLANNED', 'cash_at_pickup' => 'NOT_APPROVED'],
                ],
                'tax' => ['regime' => 'GST', 'status' => 'PENDING', 'prices_include_tax' => null],
                'legal' => ['documents' => ['terms' => 'DRAFT_PENDING_APPROVAL', 'privacy' => 'DRAFT_PENDING_APPROVAL', 'refund' => 'DRAFT_PENDING_APPROVAL', 'cookie' => 'DRAFT_PENDING_APPROVAL']],
                'address' => ['postal_code_label' => 'PIN code', 'postal_code_pattern' => '^[1-9][0-9]{5}$', 'admin_area_label' => 'State / UT'],
                'ordering' => [],
                'locked_features' => ['cash_at_pickup', 'cross_border_ordering'],
            ])->save();
        }

        $markets->flush();
    }
}
