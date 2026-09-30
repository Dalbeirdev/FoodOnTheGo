<?php

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Enums\SecurityEventType;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Models\SecurityEvent;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\TokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function me(array $headers): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/auth/me', $headers);
    }

    public function test_requests_without_a_valid_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->me(['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized();
        $this->me(['Authorization' => 'Bearer 999|'.str_repeat('a', 40)])->assertUnauthorized();
    }

    public function test_the_api_is_stateless_a_cookie_or_session_never_authenticates(): void
    {
        $customer = Customer::factory()->create();

        $this->withSession(['login_customer_'.sha1('x') => $customer->id])
            ->withCredentials()->withUnencryptedCookie('laravel_session', 'anything')
            ->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertSame([], config('sanctum.guard'), 'no session guard may back the API');
    }

    public function test_tokens_are_stored_hashed_carry_an_expiry_and_stop_working_after_it(): void
    {
        $customer = Customer::factory()->create();
        $issued = app(TokenIssuer::class)->issue($customer, 'android');
        $plain = $issued->plainTextToken;

        $row = DB::table('personal_access_tokens')->first();
        $this->assertSame(hash('sha256', explode('|', $plain)[1]), $row->token);
        $this->assertStringNotContainsString(explode('|', $plain)[1], json_encode($row));
        $this->assertSame('customer', $row->tokenable_type);
        $this->assertNotNull($row->expires_at);
        $this->assertNotNull($row->public_id);

        $this->me(['Authorization' => 'Bearer '.$plain])->assertOk();

        $this->travel(config('auth_security.token_ttl_minutes.customer') + 1)->minutes();
        $this->me(['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
    }

    public function test_admin_and_restaurant_tokens_live_shorter_than_customer_tokens(): void
    {
        $issuer = app(TokenIssuer::class);
        $customer = $issuer->issue(Customer::factory()->create())->accessToken->expires_at;
        $restaurant = $issuer->issue(RestaurantUser::factory()->create())->accessToken->expires_at;
        $admin = $issuer->issue(AdminUser::factory()->create())->accessToken->expires_at;

        $this->assertTrue($admin->lessThan($restaurant));
        $this->assertTrue($restaurant->lessThan($customer));
    }

    public function test_logout_revokes_only_the_current_session(): void
    {
        $customer = Customer::factory()->create();
        $web = $this->bearer($customer, 'web');
        $android = $this->bearer($customer, 'android');

        $this->postJson('/api/v1/auth/logout', [], $web)->assertNoContent();

        $this->me($web)->assertUnauthorized();
        $this->me($android)->assertOk();
        $this->assertSame(1, SecurityEvent::query()->where('event', SecurityEventType::Logout)->count());
    }

    public function test_logout_all_revokes_every_session_of_the_account_and_nobody_elses(): void
    {
        $customer = Customer::factory()->create();
        $other = $this->bearer(Customer::factory()->create());
        $web = $this->bearer($customer, 'web');
        $android = $this->bearer($customer, 'android');

        $this->postJson('/api/v1/auth/logout-all', [], $web)->assertNoContent();

        $this->me($web)->assertUnauthorized();
        $this->me($android)->assertUnauthorized();
        $this->me($other)->assertOk();
    }

    public function test_sessions_can_be_listed_and_revoked_one_by_one(): void
    {
        $admin = AdminUser::factory()->create();
        $web = $this->bearer($admin, 'web');
        $laptop = $this->bearer($admin, 'laptop');

        $sessions = $this->getJson('/api/v1/auth/sessions', $web)->assertOk()->assertJsonCount(2)->json();
        $this->assertEqualsCanonicalizing(['web', 'laptop'], array_column($sessions, 'device'));
        $this->assertSame(['id', 'device', 'current', 'created_at', 'last_used_at', 'expires_at'], array_keys($sessions[0]));
        $this->assertCount(1, array_filter($sessions, fn (array $s): bool => $s['current']));

        $laptopId = collect($sessions)->firstWhere('device', 'laptop')['id'];
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/auth/sessions/'.$laptopId, [], $web)->assertNoContent();

        $this->me($laptop)->assertUnauthorized();
        $this->me($web)->assertOk();
    }

    public function test_a_session_of_another_account_cannot_be_seen_or_revoked(): void
    {
        $victim = Customer::factory()->create();
        $victimHeaders = $this->bearer($victim, 'victim-phone');
        $victimSession = DB::table('personal_access_tokens')->value('public_id');

        $attacker = $this->bearer(Customer::factory()->create(), 'attacker');

        $this->assertNotContains($victimSession, array_column($this->getJson('/api/v1/auth/sessions', $attacker)->assertOk()->json(), 'id'));
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/auth/sessions/'.$victimSession, [], $attacker)->assertNotFound()->assertJsonPath('error.code', 'session_not_found');

        $this->me($victimHeaders)->assertOk();
    }

    public function test_suspending_an_account_ends_its_existing_sessions_immediately(): void
    {
        $status = app(AccountStatusService::class);

        $customer = Customer::factory()->create();
        $staff = RestaurantUser::factory()->create();
        $admin = AdminUser::factory()->create();
        $sessions = [$this->bearer($customer), $this->bearer($staff), $this->bearer($admin)];

        $status->change($customer, CustomerStatus::Suspended);
        $status->change($staff, StaffStatus::Disabled);
        $status->change($admin, StaffStatus::Suspended, reason: 'Security review');

        foreach ($sessions as $headers) {
            $this->me($headers)->assertUnauthorized();
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(3, SecurityEvent::query()->where('event', SecurityEventType::AccountStatusChanged)->count());
    }

    public function test_a_token_that_outlives_a_status_change_is_still_refused_on_every_request(): void
    {
        $admin = AdminUser::factory()->create();
        $headers = $this->bearer($admin);

        // Status changed behind the service's back (e.g. directly in the database): the token row still exists.
        DB::table('admin_users')->where('id', $admin->id)->update(['status' => 'SUSPENDED']);

        $this->me($headers)->assertForbidden()->assertJsonPath('error.code', 'account_not_active');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/markets', $headers)->assertForbidden();

        DB::table('admin_users')->where('id', $admin->id)->update(['status' => 'ACTIVE']);
        $this->me($headers)->assertOk();
    }

    public function test_reactivation_does_not_resurrect_old_sessions(): void
    {
        $status = app(AccountStatusService::class);
        $customer = Customer::factory()->create();
        $old = $this->bearer($customer);

        $status->change($customer, CustomerStatus::Suspended);
        $status->change($customer, CustomerStatus::Active);

        $this->me($old)->assertUnauthorized();
        $this->me($this->bearer($customer))->assertOk();
    }
}
