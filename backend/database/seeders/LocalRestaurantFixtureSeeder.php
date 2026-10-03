<?php

namespace Database\Seeders;

use App\Enums\MembershipStatus;
use App\Enums\PrincipalType;
use App\Enums\RestaurantImageType;
use App\Models\Cuisine;
use App\Models\Market;
use App\Models\RestaurantAdminNote;
use App\Models\RestaurantFeature;
use App\Models\RestaurantImage;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationHour;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantSpecialHour;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Restaurant\LocationGeography;
use App\Services\Restaurant\PickupSettingsService;
use App\Services\Restaurant\RestaurantCatalog;
use App\Services\Restaurant\RestaurantStaffService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT FIXTURES — local and testing only, never production. None of these restaurants exists, and a
 * restaurant listed here does NOT mean FoodOnTheGo has onboarded anyone: names, addresses and statuses are
 * invented so that every availability outcome can be exercised:
 *
 *   approved, open, accepting ........ Burger Hub and most others (by the clock)
 *   approved, closed ................. Night Owl Kitchen by day (23:00–04:00), anything outside its hours
 *   approved, not accepting .......... Hoshiarpur Sweets & Snacks (paused)
 *   temporarily closed ............... Wok Express
 *   organization suspended ........... Expressway Grill
 *   under review / draft / rejected .. Surat Dhokla House, Tandoori Trails, Curry Leaf Kitchen
 *   outside every service area ....... Lakeside Café (Udaipur)
 *   area not served right now ........ Highway Coffee Co. (Vapi, paused)
 *   several locations, one business .. Riverside Hospitality Group (three outlets in Noida)
 *   several periods a day ............ Spice Nest;   overnight: Pathankot Punjabi Rasoi;   24 hours: Dhaba Junction
 *   closed weekday ................... Gujarati Bhojanalay (Mondays)
 *   special closure / special hours .. Ambala Chai Point, Rajwada Thali House
 *
 * Slugs match the fixtures the clients used before this module. Geography is never written by hand: the
 * city, the region and the service area of each outlet are resolved from its coordinates, exactly as for a
 * location an administrator creates. Existing rows are left untouched, so changes made locally survive a
 * re-seed; staff memberships are created once and their role assignments are derived from them.
 */
class LocalRestaurantFixtureSeeder extends Seeder
{
    private const IMAGES = '/images/food-';

    /** key => [public id, legal name, display name, status, rejection category, note for the restaurant] */
    private const ORGANIZATIONS = [
        'riverside' => [LocalFixtureSeeder::ORGANIZATION_A, 'Riverside Hospitality Group Pvt. Ltd.', 'Riverside Hospitality Group', 'APPROVED', null, null],
        'second-kitchen' => [LocalFixtureSeeder::ORGANIZATION_B, 'Second Kitchen Foods LLP', 'Second Kitchen', 'APPROVED', null, null],
        'spice-nest' => [null, 'Spice Nest Restaurants Pvt. Ltd.', 'Spice Nest', 'APPROVED', null, null],
        'wok-express' => [null, 'Wok Express Foods Pvt. Ltd.', 'Wok Express', 'APPROVED', null, null],
        'dhaba-junction' => [null, 'Dhaba Junction Highway Foods', 'Dhaba Junction', 'APPROVED', null, null],
        'hoshiarpur-sweets' => [null, 'Hoshiarpur Sweets & Snacks', 'Hoshiarpur Sweets & Snacks', 'APPROVED', null, null],
        'pathankot-rasoi' => [null, 'Pathankot Punjabi Rasoi', 'Pathankot Punjabi Rasoi', 'APPROVED', null, null],
        'ambala-chai' => [null, 'Ambala Chai Point', 'Ambala Chai Point', 'APPROVED', null, null],
        'rajwada-thali' => [null, 'Rajwada Hospitality LLP', 'Rajwada Thali House', 'APPROVED', null, null],
        'lakeside-cafe' => [null, 'Lakeside Café Udaipur', 'Lakeside Café', 'APPROVED', null, null],
        'gujarati-bhojanalay' => [null, 'Gujarati Bhojanalay', 'Gujarati Bhojanalay', 'APPROVED', null, null],
        'expressway-grill' => [null, 'Expressway Grill Foods Pvt. Ltd.', 'Expressway Grill', 'SUSPENDED', null, 'Suspended while a hygiene complaint is reviewed. Our onboarding team will contact you.'],
        'surat-dhokla' => [null, 'Surat Dhokla House', 'Surat Dhokla House', 'UNDER_REVIEW', null, null],
        'highway-coffee' => [null, 'Highway Coffee Company', 'Highway Coffee Co.', 'APPROVED', null, null],
        'night-owl' => [null, 'Night Owl Kitchens Pvt. Ltd.', 'Night Owl Kitchen', 'APPROVED', null, null],
        'tandoori-trails' => [null, 'Tandoori Trails', 'Tandoori Trails', 'DRAFT', null, null],
        'curry-leaf' => [null, 'Curry Leaf Kitchens', 'Curry Leaf Kitchen', 'REJECTED', 'INCOMPLETE_DOCUMENTS', 'The food-licence copy was missing from the application. Please send it and apply again.'],
    ];

    /**
     * slug => [organization, public id, name, branch label, latitude, longitude, address, postal code, status,
     *          cuisines, features, price level, preparation minutes, image, weekly hours, short description, extras]
     *
     * Weekly hours: list of [days, opens, closes]; days = null means every day (0 = Sunday).
     */
    private const LOCATIONS = [
        'burger-hub' => ['riverside', LocalFixtureSeeder::LOCATION_A1, 'Burger Hub', 'Sector 62 · Noida', 28.6285, 77.3652, 'Sector 62, Noida, Uttar Pradesh 201309, India', '201309', 'APPROVED',
            ['burgers', 'fast_food', 'american'], ['quick_pickup', 'parking'], 2, 12, 'burger', [[null, '08:00', '23:30']], 'Juicy burgers and crispy fries for travellers on the go.', []],
        'brew-bites' => ['riverside', null, 'Brew & Bites', 'Sector 63 · Noida', 28.6221, 77.3852, 'Sector 63, Noida, Uttar Pradesh 201301, India', '201301', 'APPROVED',
            ['cafe', 'beverages', 'snacks'], ['quick_pickup', 'wifi'], 1, 8, 'coffee', [[null, '06:30', '23:00']], 'Coffee, chai and quick bites from early morning.', []],
        'healthy-bites' => ['riverside', null, 'Healthy Bites', 'Sector 18 · Noida', 28.5686, 77.3216, 'Sector 18, Noida, Uttar Pradesh 201301, India', '201301', 'APPROVED',
            ['healthy', 'salads', 'continental'], ['outdoor_seating', 'vegan_options', 'vegan'], 3, 10, 'salad', [[null, '07:00', '22:00']], 'Salads, bowls and fresh juices.', []],
        'pizza-point' => ['second-kitchen', null, 'Pizza Point', 'Sector 18 · Noida', 28.5708, 77.3261, 'Sector 18, Noida, Uttar Pradesh 201301, India', '201301', 'APPROVED',
            ['pizza', 'italian'], ['veg_options', 'parking', 'outdoor_seating'], 2, 15, 'pizza', [[null, '11:00', '23:00']], 'Wood-fired pizzas, ready when you arrive.', []],
        'spice-nest' => ['spice-nest', null, 'Spice Nest', null, 28.6199, 77.3804, 'Sector 62, Noida, Uttar Pradesh 201309, India', '201309', 'APPROVED',
            ['north_indian'], ['pure_veg', 'family_friendly', 'parking', 'vegetarian'], 2, 18, 'curry', [[null, '10:00', '15:00'], [null, '18:00', '22:30']], 'North Indian vegetarian thalis and curries.', []],
        'wok-express' => ['wok-express', null, 'Wok Express', null, 28.6158, 77.3542, 'Sector 62, Noida, Uttar Pradesh 201309, India', '201309', 'APPROVED',
            ['chinese', 'asian'], ['quick_pickup', 'veg_options', 'parking'], 2, 14, 'noodles', [[null, '11:00', '23:00']], 'Noodles and stir-fries, tossed to order.', ['operational_status' => 'TEMPORARILY_CLOSED']],
        'dhaba-junction-ropar' => ['dhaba-junction', null, 'Dhaba Junction', 'NH-205 · Rupnagar', 30.9712, 76.5301, 'NH-205, Rupnagar, Punjab 140001, India', '140001', 'APPROVED',
            ['punjabi', 'north_indian'], ['parking', 'highway_access', 'family_friendly', 'vegetarian'], 1, 15, 'curry', [[null, '00:00', '00:00']], 'A highway dhaba that never closes.', []],
        'hoshiarpur-sweets-and-snacks' => ['hoshiarpur-sweets', null, 'Hoshiarpur Sweets & Snacks', null, 31.5309, 75.9048, 'Jalandhar Road, Hoshiarpur, Punjab 146001, India', '146001', 'APPROVED',
            ['sweets', 'snacks'], ['quick_pickup', 'pure_veg', 'vegetarian'], 1, 6, 'coffee', [[null, '07:00', '21:30']], 'Fresh mithai and namkeen.', ['pause_reason' => 'Kitchen at capacity']],
        'pathankot-punjabi-rasoi' => ['pathankot-rasoi', null, 'Pathankot Punjabi Rasoi', null, 32.2598, 75.6497, 'Dalhousie Road, Pathankot, Punjab 145001, India', '145001', 'APPROVED',
            ['punjabi', 'tandoor'], ['parking', 'outdoor_seating', 'halal'], 2, 20, 'curry', [[null, '11:00', '02:00']], 'Tandoor and Punjabi classics, open late.', []],
        'ambala-chai-point' => ['ambala-chai', null, 'Ambala Chai Point', null, 30.3812, 76.7712, 'GT Road, Ambala Cantt, Haryana 133001, India', '133001', 'APPROVED',
            ['cafe', 'beverages'], ['quick_pickup', 'highway_access'], 1, 5, 'coffee', [[null, '05:30', '23:30']], 'Chai and snacks on the GT Road.',
            ['special' => [['+1 day', true, [], 'Closed for staff training'], ['2026-11-08', true, [], 'Closed for Diwali']]]],
        'jaipur-rajwada-thali' => ['rajwada-thali', null, 'Rajwada Thali House', null, 26.9201, 75.7801, 'MI Road, Jaipur, Rajasthan 302001, India', '302001', 'APPROVED',
            ['rajasthani', 'thali'], ['pure_veg', 'family_friendly', 'vegetarian'], 2, 20, 'curry', [[null, '11:00', '22:30']], 'Unlimited Rajasthani thali.',
            ['special' => [['2026-11-08', false, [['11:00', '16:00']], 'Festival hours']]]],
        'udaipur-lakeside-cafe' => ['lakeside-cafe', null, 'Lakeside Café', null, 24.5854, 73.7125, 'Lake Pichola Road, Udaipur, Rajasthan 313001, India', '313001', 'APPROVED',
            ['cafe', 'continental'], ['outdoor_seating', 'wifi'], 3, 12, 'coffee', [[null, '08:00', '23:00']], 'Coffee with a view of the lake.', []],
        'ahmedabad-gujarati-bhojan' => ['gujarati-bhojanalay', null, 'Gujarati Bhojanalay', null, 23.0225, 72.5714, 'Ashram Road, Ahmedabad, Gujarat 380009, India', '380009', 'APPROVED',
            ['gujarati', 'thali'], ['pure_veg', 'vegetarian', 'parking'], 1, 15, 'curry', [[[0, 2, 3, 4, 5, 6], '10:30', '22:00']], 'Home-style Gujarati thali. Closed on Mondays.', []],
        'vadodara-expressway-grill' => ['expressway-grill', null, 'Expressway Grill', null, 22.3072, 73.1812, 'NH-48, Vadodara, Gujarat 390001, India', '390001', 'APPROVED',
            ['grill', 'fast_food'], ['parking', 'highway_access'], 2, 14, 'burger', [[null, '09:00', '23:00']], 'Grills and burgers by the expressway.', []],
        'surat-dhokla-house' => ['surat-dhokla', null, 'Surat Dhokla House', null, 21.1702, 72.8311, 'Ring Road, Surat, Gujarat 395002, India', '395002', 'UNDER_REVIEW',
            ['snacks', 'gujarati'], ['quick_pickup', 'vegetarian'], 1, 8, 'coffee', [[null, '07:00', '22:00']], 'Dhokla, khaman and farsan.', []],
        'vapi-highway-coffee' => ['highway-coffee', null, 'Highway Coffee Co.', null, 20.3893, 72.9106, 'NH-48, Vapi, Gujarat 396191, India', '396191', 'APPROVED',
            ['cafe', 'beverages'], ['highway_access'], 1, 5, 'coffee', [[null, '06:00', '00:00']], 'Coffee stop on NH-48.', []],
        'night-owl-kitchen' => ['night-owl', null, 'Night Owl Kitchen', 'Connaught Place · Delhi', 28.6328, 77.2197, 'Connaught Place, New Delhi, Delhi 110001, India', '110001', 'APPROVED',
            ['biryani', 'north_indian'], ['quick_pickup'], 2, 20, 'curry', [[null, '23:00', '04:00']], 'Late-night biryani and rolls.', []],
        'tandoori-trails-karnal' => ['tandoori-trails', null, 'Tandoori Trails', 'NH-44 · Karnal', 29.69, 76.99, 'NH-44, Karnal, Haryana 132001, India', '132001', 'DRAFT',
            ['tandoor', 'north_indian'], ['parking', 'highway_access'], 2, 18, 'curry', [], null, []],
        'curry-leaf-gurugram' => ['curry-leaf', null, 'Curry Leaf Kitchen', 'Cyber City · Gurugram', 28.462, 77.03, 'Cyber City, Gurugram, Haryana 122002, India', '122002', 'REJECTED',
            ['south_indian'], ['veg_options'], 2, 15, 'curry', [[null, '08:00', '22:00']], 'Dosa, idli and filter coffee.',
            ['rejection_category' => 'INCOMPLETE_DOCUMENTS', 'status_note' => 'The food-licence copy was missing from the application. Please send it and apply again.']],
    ];

    /** e-mail => [organization, role, status, location slugs (null = all locations)] */
    private const STAFF = [
        'john@riverside.example' => ['riverside', 'OWNER', 'ACTIVE', null],
        'sarah@riverside.example' => ['riverside', 'MANAGER', 'ACTIVE', ['burger-hub', 'brew-bites']],
        'mike@riverside.example' => ['riverside', 'ORDER_STAFF', 'ACTIVE', ['burger-hub']],
        'emily@riverside.example' => ['riverside', 'MENU_MANAGER', 'ACTIVE', null],
        'yuki@riverside.example' => ['riverside', 'VIEWER', 'INVITED', ['healthy-bites']],
        'suspended@riverside.example' => ['riverside', 'VIEWER', 'SUSPENDED', null],
        'owner@second-kitchen.example' => ['second-kitchen', 'OWNER', 'ACTIVE', null],
    ];

    public function run(LocationGeography $geography, PickupSettingsService $pickup, RestaurantStaffService $staff, RestaurantCatalog $catalog): void
    {
        $india = Market::query()->where('country_code', 'IN')->first();
        if ($india === null) {
            return;
        }

        $organizations = $this->organizations($india);
        $locations = $this->locations($india, $organizations, $geography, $pickup);
        $this->staff($organizations, $locations, $staff);

        $catalog->flush();
    }

    /**
     * @return array<string, RestaurantOrganization>
     */
    private function organizations(Market $india): array
    {
        $organizations = [];
        foreach (self::ORGANIZATIONS as $key => [$publicId, $legal, $display, $status, $category, $note]) {
            $organizations[$key] = RestaurantOrganization::query()->where('slug', $key)->first() ?? tap((new RestaurantOrganization)->forceFill([
                'public_id' => $publicId,
                'legal_name' => $legal,
                'display_name' => $display,
                'slug' => $key,
                'status' => $status,
                'primary_market_id' => $india->getKey(),
                'rejection_category' => $category,
                'status_note' => $note,
                'submitted_at' => $status === 'DRAFT' ? null : now()->subDays(20),
                'approved_at' => in_array($status, ['APPROVED', 'SUSPENDED'], true) ? now()->subDays(14) : null,
                'suspended_at' => $status === 'SUSPENDED' ? now()->subDays(2) : null,
            ]))->save();
        }

        if (! RestaurantAdminNote::query()->exists()) {
            foreach ([
                'expressway-grill' => 'Fixture note: complaint received from two customers on the same day; kitchen inspection requested.',
                'curry-leaf' => 'Fixture note: applicant uploaded a blurred licence scan. Asked for a new copy by phone.',
            ] as $key => $note) {
                RestaurantAdminNote::query()->create(['organization_id' => $organizations[$key]->getKey(), 'note' => $note, 'created_at' => now()->subDays(2)]);
            }
        }

        return $organizations;
    }

    /**
     * @param  array<string, RestaurantOrganization>  $organizations
     * @return array<string, RestaurantLocation>
     */
    private function locations(Market $india, array $organizations, LocationGeography $geography, PickupSettingsService $pickup): array
    {
        $cuisines = Cuisine::query()->pluck('id', 'code');
        $features = RestaurantFeature::query()->pluck('id', 'code');
        $locations = [];

        foreach (self::LOCATIONS as $slug => [$org, $publicId, $name, $branch, $lat, $lng, $address, $postal, $status, $cuisineCodes, $featureCodes, $price, $prep, $image, $hours, $short, $extra]) {
            $existing = RestaurantLocation::query()->where('restaurant_locations.market_id', $india->getKey())->where('restaurant_locations.slug', $slug)->first();
            if ($existing !== null) {
                $locations[$slug] = $existing;

                continue;
            }

            $place = $geography->resolve($india, $lat, $lng);
            $paused = isset($extra['pause_reason']);
            $live = in_array($status, ['APPROVED', 'SUSPENDED'], true);

            $location = (new RestaurantLocation)->forceFill([
                'public_id' => $publicId,
                'organization_id' => $organizations[$org]->getKey(),
                'market_id' => $india->getKey(),
                'region_id' => $place['region_id'],
                'city_id' => $place['city']->getKey(),
                'service_area_id' => $place['service_area']?->getKey(),
                'service_area_resolved_at' => now(),
                'name' => $name,
                'branch_label' => $branch,
                'slug' => $slug,
                'status' => $status,
                'operational_status' => $extra['operational_status'] ?? 'OPERATING',
                'accepting_orders' => ! $paused,
                'pause_reason' => $extra['pause_reason'] ?? null,
                'paused_at' => $paused ? now() : null,
                'timezone' => $place['city']->timezone,
                'currency' => $india->default_currency,
                'locale' => $india->default_locale,
                'formatted_address' => $address,
                'postal_code' => $postal,
                'short_description' => $short,
                'description' => $short === null ? null : $short.' Order ahead on FoodOnTheGo and pick up without leaving your route. (Development fixture — not a real restaurant.)',
                'pickup_instructions' => $live ? 'Show your pickup code at the counter.' : null,
                'price_level' => $price,
                'rejection_category' => $extra['rejection_category'] ?? null,
                'status_note' => $extra['status_note'] ?? null,
                'submitted_at' => $status === 'DRAFT' ? null : now()->subDays(18),
                'approved_at' => $live ? now()->subDays(14) : null,
            ])->insertWithSpatial(['location' => RestaurantLocation::pointExpression($lat, $lng)]);

            $position = 0;
            $location->cuisines()->sync(collect($cuisineCodes)->mapWithKeys(function (string $code) use ($cuisines, &$position): array {
                return [$cuisines[$code] => ['position' => $position++]];
            })->all());
            $location->features()->sync(array_map(fn (string $code): int => (int) $features[$code], $featureCodes));

            foreach ([RestaurantImageType::Cover, RestaurantImageType::Gallery] as $order => $type) {
                (new RestaurantImage)->forceFill([
                    'organization_id' => $organizations[$org]->getKey(), 'location_id' => $location->getKey(), 'type' => $type, 'path' => self::IMAGES.$image.'.jpg',
                    'alt_text' => $name, 'mime_type' => 'image/jpeg', 'display_order' => $order,
                ])->save();
            }

            $this->hours($location, $hours, $extra['special'] ?? []);

            $pickup->createDefaults($location, $india)->forceFill(['default_prep_minutes' => $prep, 'minimum_lead_minutes' => $prep])->save();

            $locations[$slug] = $location;
        }

        return $locations;
    }

    /**
     * @param  list<array{0: list<int>|null, 1: string, 2: string}>  $weekly
     * @param  list<array{0: string, 1: bool, 2: list<array{0: string, 1: string}>, 3: string}>  $special
     */
    private function hours(RestaurantLocation $location, array $weekly, array $special): void
    {
        $sequence = [];
        foreach ($weekly as [$days, $opens, $closes]) {
            foreach ($days ?? range(0, 6) as $day) {
                RestaurantLocationHour::query()->create([
                    'location_id' => $location->getKey(), 'kind' => RestaurantLocationHour::OPENING, 'day_of_week' => $day,
                    'opens_at' => $opens, 'closes_at' => $closes, 'sequence' => $sequence[$day] = ($sequence[$day] ?? -1) + 1,
                ]);
            }
        }

        foreach ($special as [$date, $closed, $periods, $note]) {
            // A relative date ("+1 day") is counted in the location's own time zone, like every date of its schedule.
            $day = str_starts_with($date, '+') ? CarbonImmutable::now($location->timezone)->modify($date)->format('Y-m-d') : $date;
            $row = (new RestaurantSpecialHour)->forceFill(['location_id' => $location->getKey(), 'date' => $day, 'is_closed' => $closed, 'public_note' => $note]);
            $row->save();
            foreach ($periods as $index => [$opens, $closes]) {
                $row->periods()->create(['opens_at' => $opens, 'closes_at' => $closes, 'sequence' => $index]);
            }
        }
    }

    /**
     * Memberships of the fixture accounts (created by LocalFixtureSeeder). The role assignments are derived
     * from the membership, so the stand-in scopes used before this module are brought in line here.
     *
     * @param  array<string, RestaurantOrganization>  $organizations
     * @param  array<string, RestaurantLocation>  $locations
     */
    private function staff(array $organizations, array $locations, RestaurantStaffService $staff): void
    {
        foreach (self::STAFF as $email => [$org, $roleCode, $status, $slugs]) {
            $user = RestaurantUser::query()->where('email', $email)->first();
            if ($user === null) {
                continue;
            }

            $organization = $organizations[$org];
            $membership = RestaurantMembership::query()->where('restaurant_user_id', $user->getKey())->where('organization_id', $organization->getKey())->first();

            if ($membership === null) {
                $membership = (new RestaurantMembership)->forceFill([
                    'restaurant_user_id' => $user->getKey(),
                    'organization_id' => $organization->getKey(),
                    'role_id' => Role::query()->where('principal_type', PrincipalType::RestaurantUser->value)->where('code', $roleCode)->firstOrFail()->getKey(),
                    'status' => $status,
                    'all_locations' => $slugs === null,
                    'invited_name' => $user->name,
                    'accepted_at' => $status === MembershipStatus::Invited->value ? null : now()->subDays(10),
                ]);
                $membership->save();
                $membership->locations()->sync(array_map(fn (string $slug): int => (int) $locations[$slug]->getKey(), $slugs ?? []));
            }

            $staff->sync($membership);
        }
    }
}
