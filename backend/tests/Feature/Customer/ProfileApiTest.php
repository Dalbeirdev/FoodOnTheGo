<?php

namespace Tests\Feature\Customer;

use App\Models\AuditEvent;
use App\Models\Cuisine;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * The customer's own profile (Module 25): reading, the fields that may change, the ones that may not, Unicode
 * names, locale options from the market, cuisine preferences from the taxonomy, the photo, and the audit trail
 * that never records personal values.
 */
class ProfileApiTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase;

    private Customer $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->rahul = $this->customer($this->market, ['name' => 'Rahul Sharma', 'email' => 'rahul@example.com', 'phone_e164' => '+919876543210']);
        $this->actingAsPrincipal($this->rahul);
    }

    public function test_the_profile_is_the_customers_own_safe_data(): void
    {
        $p = $this->getJson('/api/v1/customer/profile')->assertOk()->json();
        $this->assertSame(['Rahul Sharma', 'Rahul Sharma', '+919876543210', true, false, 'rahul@example.com', false, 'en-IN', ['en-IN'], 'IN', 'ACTIVE', 1], [$p['name'], $p['display_name'], $p['phone'], $p['phone_verified'], $p['phone_editable'], $p['email'], $p['email_verified'], $p['preferred_locale'], $p['locale_options'], $p['market'], $p['status'], $p['version']]);
        $this->assertStringStartsWith('+91', $p['phone_masked']);
        $this->assertNotSame($p['phone'], $p['phone_masked']);
        $this->assertNull($p['avatar']);
        $this->assertNull($p['deletion_requested_at']);
        $this->assertSame([], $p['favorite_cuisines']);
        foreach (['id_internal', 'market_id', 'token', 'tokens', 'otp', 'password', 'terms_accepted_at', 'last_login_at', 'notes'] as $never) {
            $this->assertArrayNotHasKey($never, $p);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $p['id']);
    }

    public function test_allowed_fields_change_with_a_version_and_the_audit_records_no_personal_values(): void
    {
        $codes = Cuisine::query()->active()->orderBy('display_order')->limit(2)->pluck('code')->all();
        $p = $this->patchJson('/api/v1/customer/profile', [
            'version' => 1, 'name' => '  Rahul  Kumar Sharma ', 'email' => ' Rahul.Sharma@Example.com ', 'date_of_birth' => '1990-03-15', 'gender' => 'male',
            'favorite_cuisines' => $codes, 'vegetarian_only' => true, 'search_radius_km' => 35,
        ])->assertOk()->json();
        $this->assertSame(['Rahul Kumar Sharma', 'rahul.sharma@example.com', '1990-03-15', 'MALE', true, 35, 2], [$p['name'], $p['email'], $p['date_of_birth'], $p['gender'], $p['vegetarian_only'], $p['search_radius_km'], $p['version']]);
        $this->assertSame($codes, array_column($p['favorite_cuisines'], 'code'));
        $this->assertNotEmpty($p['favorite_cuisines'][0]['name']);

        $audit = AuditEvent::query()->where('action', 'customer.profile_updated')->latest('id')->firstOrFail();
        $this->assertSame('customers', $audit->target_type);
        $this->assertSame($this->rahul->public_id, $audit->target_public_id);
        $this->assertEquals(['from' => null, 'to' => 'changed'], $audit->changes['name']);
        $this->assertEquals(['from' => null, 'to' => 'changed'], $audit->changes['email']);
        $this->assertEquals(['from' => null, 'to' => 'changed'], $audit->changes['date_of_birth']);
        $this->assertSame(35, $audit->changes['search_radius_km']['to']);
        $this->assertStringNotContainsString('rahul.sharma@example.com', json_encode($audit->changes));
        $this->assertStringNotContainsString('1990', json_encode($audit->changes));

        // a stale version changes nothing
        $this->patchJson('/api/v1/customer/profile', ['version' => 1, 'name' => 'Stale'])->assertStatus(409)->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 2);
        $this->assertSame('Rahul Kumar Sharma', $this->rahul->fresh()->name);
        // without a version the last write wins (clients that have no version yet)
        $this->patchJson('/api/v1/customer/profile', ['email' => null, 'gender' => '', 'favorite_cuisines' => []])->assertOk()->assertJsonPath('email', null)->assertJsonPath('gender', null)->assertJsonPath('favorite_cuisines', [])->assertJsonPath('version', 3);
    }

    public function test_unicode_names_are_kept_as_typed(): void
    {
        foreach (['राहुल शर्मा', 'ਰਾਹੁਲ ਸ਼ਰਮਾ', 'ராகுல் ஷர்மா', 'José Ñandú', '李小龍'] as $name) {
            $this->patchJson('/api/v1/customer/profile', ['name' => $name])->assertOk()->assertJsonPath('name', $name)->assertJsonPath('display_name', $name);
        }
        $this->assertSame('李小龍', $this->rahul->fresh()->name);
    }

    public function test_validation_refuses_bad_email_markup_future_birthdays_unknown_cuisines_and_bad_radius(): void
    {
        $fields = fn (array $body): array => $this->patchJson('/api/v1/customer/profile', $body)->assertUnprocessable()->json('error.details.fields');
        $this->assertArrayHasKey('email', $fields(['email' => 'not-an-email']));
        $this->assertArrayHasKey('name', $fields(['name' => 'Rahul <b>Sharma</b>']));
        $this->assertArrayHasKey('name', $fields(['name' => str_repeat('x', 121)]));
        $this->assertArrayHasKey('date_of_birth', $fields(['date_of_birth' => now()->addDay()->toDateString()]));
        $this->assertArrayHasKey('date_of_birth', $fields(['date_of_birth' => '15/03/1990']));
        $this->assertArrayHasKey('gender', $fields(['gender' => 'X']));
        $this->assertSame(['Unknown cuisine: martian'], $fields(['favorite_cuisines' => ['martian']])['favorite_cuisines']);
        $this->assertArrayHasKey('search_radius_km', $fields(['search_radius_km' => 2]));
        $this->assertArrayHasKey('search_radius_km', $fields(['search_radius_km' => 500]));
        $this->assertArrayHasKey('favorite_cuisines', $fields(['favorite_cuisines' => array_fill(0, 11, 'x')]));
        $this->assertSame(['This language is not available in your market.'], $fields(['preferred_locale' => 'fr-FR'])['preferred_locale']);
        $this->assertSame('Rahul Sharma', $this->rahul->fresh()->name);
    }

    public function test_phone_status_verification_market_and_roles_cannot_be_changed_through_the_profile(): void
    {
        $fields = $this->patchJson('/api/v1/customer/profile', [
            'name' => 'Rahul Sharma', 'phone' => '+919999999999', 'phone_e164' => '+919999999999', 'status' => 'SUSPENDED', 'phone_verified_at' => '2020-01-01T00:00:00Z',
            'market' => 'US', 'market_id' => 99, 'roles' => ['admin'], 'permissions' => ['admin.customers.manage'], 'terms_accepted_at' => '2020-01-01T00:00:00Z', 'customer_id' => 1,
        ])->assertUnprocessable()->json('error.details.fields');
        foreach (['phone', 'phone_e164', 'status', 'phone_verified_at', 'market', 'market_id', 'roles', 'permissions', 'terms_accepted_at', 'customer_id'] as $field) {
            $this->assertArrayHasKey($field, $fields, $field);
        }
        $fresh = $this->rahul->fresh();
        $this->assertSame(['+919876543210', 'ACTIVE'], [$fresh->phone_e164, $fresh->status->value]);
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertSame((int) $this->market->getKey(), (int) $fresh->market_id);
    }

    public function test_the_profile_photo_is_checked_by_content_stored_behind_the_disk_and_served_by_the_media_route(): void
    {
        Storage::fake('public');
        $p = $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->image('me.jpg', 600, 600)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertMatchesRegularExpression('#/api/v1/media/avatars/[0-9a-f-]{36}/[0-9a-f-]{36}\.jpg$#', $p['avatar']['url']);
        $path = $this->rahul->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringNotContainsString($path, json_encode($p));
        $this->get(substr($p['avatar']['url'], strpos($p['avatar']['url'], '/api/v1/media/')))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // replacing deletes the old file; refusals: not an image, too small, too large
        $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->image('me2.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->createWithContent('x.jpg', '<svg onload=alert(1)/>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->image('tiny.jpg', 50, 50)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->image('big.jpg', 2500, 200)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->image('heavy.jpg', 300, 300)->size(3 * 1024)], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->deleteJson('/api/v1/customer/profile/avatar')->assertOk()->assertJsonPath('avatar', null);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->deleteJson('/api/v1/customer/profile/avatar')->assertOk();
    }

    public function test_another_customer_never_sees_this_profile_and_a_guest_gets_401(): void
    {
        $other = $this->customer($this->market, ['name' => 'Asha Verma']);
        $this->actingAsPrincipal($other);
        $this->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('name', 'Asha Verma');
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Taken over'])->assertOk();
        $this->assertSame('Rahul Sharma', $this->rahul->fresh()->name, 'the other customer changed only their own profile');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/customer/profile', ['name' => 'Nobody'])->assertUnauthorized();
    }

    public function test_the_module_21_onboarding_profile_route_still_works_for_the_sign_up_step(): void
    {
        $this->patchJson('/api/v1/auth/customer/profile', ['name' => 'Rahul S', 'accept_terms' => true])->assertOk()->assertJsonPath('name', 'Rahul S');
        $this->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('name', 'Rahul S');
    }
}
