<?php

namespace Database\Seeders;

use App\Enums\CustomerGender;
use App\Enums\CustomerStatus;
use App\Models\Cuisine;
use App\Models\Customer;
use App\Models\CustomerNotificationPreference;
use App\Models\CustomerSavedLocation;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Services\Customer\CustomerFavoriteService;
use App\Services\Customer\PaymentMethodReferenceService;
use App\Services\Customer\SavedLocationService;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT CUSTOMER ACCOUNT FIXTURES — local and testing only, never production. Fictional people, test
 * phone numbers, invented addresses, fake provider references. No real credential of any kind exists here.
 *
 * Rahul Sharma (+91 98765 43210, created by LocalFixtureSeeder) gets a complete account:
 *   profile ............ date of birth, gender, three favourite cuisines, search radius 20 km
 *   favorites .......... Burger Hub (open), Spice Nest, Night Owl Kitchen (closed by day), Vadodara Expressway
 *                        Grill (suspended organization → kept, returned as unavailable)
 *   saved locations .... Home (Noida Sector 62, with coordinates), Work (Noida Sector 142), Mumbai hotel (outside
 *                        every service area), Dubai airport (outside India — international architecture), a place
 *                        without coordinates (coverage unknown)
 *
 *   payment methods .... Visa •••• 4242 (default), UPI ra***@okaxis, an expired Mastercard — provider "development"
 *   preferences ........ e-mail switched off for order updates; marketing stays off (never inferred)
 * Asha Verma (+91 98765 43212) is a second active customer with one favorite, so isolation can be shown.
 *
 * Re-seeding leaves existing rows untouched (favorites and references are deduplicated, locations are only
 * created when the customer has none).
 */
class LocalCustomerFixtureSeeder extends Seeder
{
    public function run(CustomerFavoriteService $favorites, SavedLocationService $locations, PaymentMethodReferenceService $payments): void
    {
        $india = Market::query()->where('country_code', 'IN')->first();
        $rahul = Customer::query()->where('phone_e164', '+919876543210')->first();
        if ($india === null || $rahul === null) {
            return;
        }
        $asha = Customer::query()->firstOrCreate(['phone_e164' => '+919876543212'], [
            'name' => 'Asha Verma', 'email' => 'asha.verma@example.com', 'status' => CustomerStatus::Active, 'phone_verified_at' => now(), 'terms_accepted_at' => now(), 'market_id' => $india->getKey(), 'preferred_locale' => 'en-IN',
        ]);

        // profile
        $codes = Cuisine::query()->whereIn('name', ['North Indian', 'Burgers', 'Healthy'])->orderBy('display_order')->pluck('code')->all();
        if ($rahul->date_of_birth === null) {
            $rahul->forceFill(['date_of_birth' => '1990-03-15', 'gender' => CustomerGender::Male, 'favorite_cuisines' => $codes, 'vegetarian_only' => false, 'search_radius_km' => 20, 'preferred_locale' => $rahul->preferred_locale ?? 'en-IN'])->save();
        }

        // favorites (customer-visible ones through the service, the hidden one directly: a favorite made before the suspension)
        $bySlug = fn (string $slug): ?RestaurantLocation => RestaurantLocation::query()->where('slug', $slug)->first();
        foreach (['burger-hub', 'spice-nest', 'night-owl-kitchen', 'vadodara-expressway-grill'] as $slug) {
            if (($location = $bySlug($slug)) !== null) {
                $favorites->add($rahul, $location);
            }
        }
        if (($pizza = $bySlug('pizza-point')) !== null) {
            $favorites->add($asha, $pizza);
        }

        // saved locations
        if (! CustomerSavedLocation::query()->where('customer_id', $rahul->getKey())->exists()) {
            foreach ([
                ['kind' => 'HOME', 'label' => 'Home', 'line1' => 'A-203, Green Valley Apartments', 'locality' => 'Sector 62', 'city' => 'Noida', 'region' => 'Uttar Pradesh', 'postal_code' => '201309', 'country_code' => 'IN', 'formatted_address' => 'A-203, Green Valley Apartments, Sector 62, Noida, Uttar Pradesh 201309, India', 'lat' => 28.6271, 'lng' => 77.3717, 'place_provider' => 'development', 'place_id' => 'dev:noida-sector-62-home'],
                ['kind' => 'WORK', 'label' => 'Work', 'line1' => 'Tower B, ABC Corporate Park', 'locality' => 'Sector 142', 'city' => 'Noida', 'region' => 'Uttar Pradesh', 'postal_code' => '201305', 'country_code' => 'IN', 'formatted_address' => 'Tower B, ABC Corporate Park, Sector 142, Noida, Uttar Pradesh 201305, India', 'lat' => 28.4987, 'lng' => 77.4115, 'place_provider' => 'development', 'place_id' => 'dev:noida-sector-142-work'],
                ['kind' => 'OTHER', 'label' => 'Mumbai hotel', 'line1' => 'Marine Drive', 'locality' => 'Churchgate', 'city' => 'Mumbai', 'region' => 'Maharashtra', 'postal_code' => '400020', 'country_code' => 'IN', 'formatted_address' => 'Marine Drive, Churchgate, Mumbai, Maharashtra 400020, India', 'lat' => 18.9432, 'lng' => 72.8236, 'place_provider' => 'development', 'place_id' => 'dev:mumbai-marine-drive'],
                ['kind' => 'OTHER', 'label' => 'Dubai airport', 'locality' => 'Al Garhoud', 'city' => 'Dubai', 'region' => 'Dubai', 'country_code' => 'AE', 'formatted_address' => 'Dubai International Airport, Al Garhoud, Dubai, United Arab Emirates', 'lat' => 25.2532, 'lng' => 55.3657, 'timezone' => 'Asia/Dubai', 'place_provider' => 'development', 'place_id' => 'dev:dxb'],
                ['kind' => 'OTHER', 'label' => "Grandma's place", 'line1' => '14, Model Town', 'locality' => 'Model Town', 'city' => 'Ludhiana', 'region' => 'Punjab', 'postal_code' => '141002', 'country_code' => 'IN', 'formatted_address' => '14, Model Town, Ludhiana, Punjab 141002, India'],
            ] as $input) {
                $locations->create($rahul, $input, $rahul);
            }
        }

        // payment-method references (fake, provider "development"); never credentials
        $payments->attach($rahul, ['type' => 'CARD', 'provider' => 'development', 'provider_customer_reference' => 'dev_cust_rahul', 'provider_payment_method_reference' => 'dev_pm_visa_4242', 'brand' => 'Visa', 'display_label' => 'Visa •••• 4242', 'last4' => '4242', 'expiry_month' => 12, 'expiry_year' => 2028, 'is_default' => true]);
        $payments->attach($rahul, ['type' => 'UPI', 'provider' => 'development', 'provider_customer_reference' => 'dev_cust_rahul', 'provider_payment_method_reference' => 'dev_pm_upi_okaxis', 'display_label' => 'UPI ra***@okaxis', 'upi_handle_masked' => 'ra***@okaxis']);
        $payments->attach($rahul, ['type' => 'CARD', 'provider' => 'development', 'provider_customer_reference' => 'dev_cust_rahul', 'provider_payment_method_reference' => 'dev_pm_mc_4444', 'brand' => 'Mastercard', 'display_label' => 'Mastercard •••• 4444', 'last4' => '4444', 'expiry_month' => 1, 'expiry_year' => 2024]);

        // one explicit notification choice; marketing is left at its default (off)
        CustomerNotificationPreference::query()->firstOrCreate(['customer_id' => $rahul->getKey(), 'category' => 'ORDER_UPDATES', 'channel' => 'EMAIL'], ['enabled' => false]);
    }
}
