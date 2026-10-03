<?php

namespace Tests\Feature\Customer;

use App\Services\Customer\PaymentMethodReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every customer-account endpoint (Module 25) answers with the shape documented in openapi/openapi.json.
 * A property that is not documented fails the test.
 */
class CustomerOpenApiContractTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase, ValidatesOpenApi;

    public function test_customer_account_responses_match_their_documented_schemas(): void
    {
        $this->setUpRestaurantWorld();
        Storage::fake('public');
        $burger = $this->hours($this->location($this->organization('Riverside'), 'Burger Hub', ['slug' => 'burger-hub']), [[null, '09:00', '22:00']]);
        $rahul = $this->customer($this->market, ['name' => 'Rahul Sharma', 'phone_e164' => '+919876543210']);
        $s = $this->sessionOf($rahul);
        $u = '/api/v1/customer';
        $json = $s + ['Accept' => 'application/json'];

        // ── Profile
        $this->assertMatchesSchema('CustomerProfile', $this->getJson("$u/profile", $s)->assertOk()->json());
        $this->assertMatchesSchema('CustomerProfile', $this->patchJson("$u/profile", ['version' => 1, 'name' => 'Rahul S', 'email' => 'rahul@example.com', 'date_of_birth' => '1990-03-15', 'gender' => 'MALE', 'search_radius_km' => 30, 'vegetarian_only' => true], $s)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->patchJson("$u/profile", ['version' => 1, 'name' => 'Stale'], $s)->assertStatus(409)->json());
        $this->assertMatchesSchema('Error', $this->patchJson("$u/profile", ['phone' => '+911234567890'], $s)->assertUnprocessable()->json());
        $this->assertMatchesSchema('CustomerProfile', $this->post("$u/profile/avatar", ['image' => UploadedFile::fake()->image('me.jpg', 400, 400)], $json)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->post("$u/profile/avatar", ['image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')], $json)->assertUnprocessable()->json());
        $this->assertMatchesSchema('CustomerProfile', $this->deleteJson("$u/profile/avatar", [], $s)->assertOk()->json());

        // ── Favorites
        $this->assertMatchesSchema('FavoriteAdded', $this->postJson("$u/favorites/burger-hub", [], $s)->assertCreated()->json());
        $this->assertMatchesSchema('FavoriteAdded', $this->postJson("$u/favorites/burger-hub", [], $s)->assertOk()->json());
        $this->assertMatchesSchema('FavoriteRestaurantPage', $this->getJson("$u/favorites", $s)->assertOk()->json());
        $burger->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertMatchesSchema('FavoriteRestaurantPage', $this->getJson("$u/favorites", $s)->assertOk()->assertJsonPath('data.0.available', false)->json());
        $burger->forceFill(['status' => 'APPROVED'])->save();
        $this->assertMatchesSchema('Error', $this->postJson("$u/favorites/no-such-restaurant", [], $s)->assertNotFound()->json());
        $this->deleteJson("$u/favorites/burger-hub", [], $s)->assertNoContent();

        // ── Saved journey locations
        $home = $this->postJson("$u/saved-locations", ['kind' => 'HOME', 'label' => 'Home', 'line1' => 'A-203, Green Valley', 'locality' => 'Sector 62', 'region' => 'Uttar Pradesh', 'postal_code' => '201309', 'country_code' => 'IN', 'formatted_address' => 'A-203, Green Valley, Sector 62, Noida', 'lat' => 28.55, 'lng' => 77.35], $s)->assertCreated()->json();
        $this->assertMatchesSchema('SavedLocation', $home);
        $this->assertMatchesSchema('SavedLocation', $this->postJson("$u/saved-locations", ['kind' => 'OTHER', 'label' => 'Dubai airport', 'country_code' => 'AE', 'formatted_address' => 'Dubai International Airport', 'lat' => 25.2532, 'lng' => 55.3657, 'timezone' => 'Asia/Dubai'], $s)->assertCreated()->json());
        $this->assertMatchesSchema('SavedLocation', $this->postJson("$u/saved-locations", ['label' => "Grandma's place", 'formatted_address' => '14, Model Town, Ludhiana'], $s)->assertCreated()->json());
        $this->assertMatchesSchema('SavedLocationList', $this->getJson("$u/saved-locations", $s)->assertOk()->assertJsonCount(3)->json());
        $this->assertMatchesSchema('SavedLocation', $this->patchJson("$u/saved-locations/{$home['id']}", ['version' => 1, 'label' => 'Home (Noida)', 'lat' => 28.56, 'lng' => 77.36], $s)->assertOk()->json());
        $this->assertMatchesSchema('SavedLocation', $this->patchJson("$u/saved-locations/{$home['id']}", ['version' => 2, 'location' => null], $s)->assertOk()->assertJsonPath('coverage.status', 'unknown')->json());
        $this->assertMatchesSchema('Error', $this->patchJson("$u/saved-locations/{$home['id']}", ['version' => 1, 'label' => 'Stale'], $s)->assertStatus(409)->json());
        $this->assertMatchesSchema('Error', $this->postJson("$u/saved-locations", ['label' => 'Half', 'lat' => 28.5], $s)->assertUnprocessable()->json());
        $this->assertMatchesSchema('SavedLocation', $this->postJson("$u/saved-locations/{$home['id']}/default", [], $s)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson("$u/saved-locations/00000000-0000-4000-8000-000000000000/default", [], $s)->assertNotFound()->json());
        $this->deleteJson("$u/saved-locations/{$home['id']}", [], $s)->assertNoContent();

        // ── Recent places
        $this->assertMatchesSchema('RecentLocation', $this->postJson("$u/recent-locations", ['label' => 'Sector 18 Metro', 'formatted_address' => 'Sector 18 Metro Station, Noida', 'lat' => 28.5708, 'lng' => 77.3261, 'place_provider' => 'development', 'place_id' => 'dev:sector18'], $s)->assertCreated()->assertJsonPath('place.provider', 'development')->json());
        $this->assertMatchesSchema('RecentLocation', $this->postJson("$u/recent-locations", ['label' => 'Somewhere', 'lat' => 28.6, 'lng' => 77.3], $s)->assertCreated()->assertJsonPath('place', null)->json());
        $this->assertMatchesSchema('RecentLocationList', $this->getJson("$u/recent-locations", $s)->assertOk()->assertJsonCount(2)->json());
        $this->assertMatchesSchema('Error', $this->postJson("$u/recent-locations", ['label' => 'Bad', 'lat' => 95, 'lng' => 0], $s)->assertUnprocessable()->json());
        $this->deleteJson("$u/recent-locations", [], $s)->assertNoContent();

        // ── Payment-method references (attached by the provider integration, never through the API)
        $service = app(PaymentMethodReferenceService::class);
        $visa = $service->attach($rahul, ['type' => 'CARD', 'provider' => 'development', 'provider_payment_method_reference' => 'dev_pm_visa', 'brand' => 'Visa', 'display_label' => 'Visa •••• 4242', 'last4' => '4242', 'expiry_month' => 12, 'expiry_year' => 2028, 'is_default' => true]);
        $upi = $service->attach($rahul, ['type' => 'UPI', 'provider' => 'development', 'provider_payment_method_reference' => 'dev_pm_upi', 'display_label' => 'UPI ra***@okaxis', 'upi_handle_masked' => 'ra***@okaxis']);
        $service->attach($rahul, ['type' => 'CARD', 'provider' => 'development', 'provider_payment_method_reference' => 'dev_pm_old', 'brand' => 'Mastercard', 'display_label' => 'Mastercard •••• 4444', 'last4' => '4444', 'expiry_month' => 1, 'expiry_year' => 2024]);
        $this->assertMatchesSchema('PaymentMethodList', $this->getJson("$u/payment-methods", $s)->assertOk()->assertJsonCount(3)->json());
        $this->assertMatchesSchema('PaymentMethodList', $this->patchJson("$u/payment-methods/{$upi->public_id}/default", [], $s)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->patchJson("$u/payment-methods/{$upi->public_id}/default", ['card_number' => '4242424242424242'], $s)->assertUnprocessable()->json());
        $this->assertMatchesSchema('PaymentMethodList', $this->deleteJson("$u/payment-methods/{$visa->public_id}", [], $s)->assertOk()->assertJsonCount(2)->json());
        $this->assertMatchesSchema('Error', $this->deleteJson("$u/payment-methods/00000000-0000-4000-8000-000000000000", [], $s)->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->postJson("$u/payment-methods", ['card_number' => '4242424242424242'], $s)->assertStatus(405)->json());

        // ── Notification preferences
        $this->assertMatchesSchema('NotificationPreferences', $this->getJson("$u/notification-preferences", $s)->assertOk()->json());
        $this->assertMatchesSchema('NotificationPreferences', $this->patchJson("$u/notification-preferences", ['preferences' => [['category' => 'PROMOTIONS', 'channel' => 'EMAIL', 'enabled' => true], ['category' => 'ORDER_UPDATES', 'channel' => 'SMS', 'enabled' => false]]], $s)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->patchJson("$u/notification-preferences", ['preferences' => [['category' => 'ACCOUNT_SECURITY', 'channel' => 'SMS', 'enabled' => false]]], $s)->assertUnprocessable()->json());

        // ── Account security: re-authentication, phone change, deletion request
        $challenge = $this->postJson("$u/account/reauth", [], $s)->assertOk()->json();
        $this->assertMatchesSchema('ReauthChallenge', $challenge);
        $this->assertMatchesSchema('Error', $this->postJson("$u/account/reauth/verify", ['challenge_id' => $challenge['challenge_id'], 'code' => '000000'], $s)->assertUnprocessable()->json());
        $this->assertMatchesSchema('ReauthResult', $this->postJson("$u/account/reauth/verify", ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], $s)->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson("$u/phone-change/request", ['phone' => '9876543210'], $s)->assertUnprocessable()->json());
        $change = $this->postJson("$u/phone-change/request", ['phone' => '9876501234'], $s)->assertOk()->json();
        $this->assertMatchesSchema('PhoneChangeChallenge', $change);
        $this->assertMatchesSchema('CustomerProfile', $this->postJson("$u/phone-change/verify", ['challenge_id' => $change['challenge_id'], 'code' => '123456'], $s)->assertOk()->assertJsonPath('phone', '+919876501234')->json());
        $this->assertMatchesSchema('Error', $this->postJson("$u/account/deletion-request", ['confirm' => false], $s)->assertUnprocessable()->json());
        $this->assertMatchesSchema('DeletionRequested', $this->postJson("$u/account/deletion-request", ['confirm' => true, 'reason' => 'Moving abroad'], $s)->assertOk()->json());

        // ── No session
        $this->app['auth']->forgetGuards();
        $this->assertMatchesSchema('Error', $this->getJson("$u/profile")->assertUnauthorized()->json());
    }
}
