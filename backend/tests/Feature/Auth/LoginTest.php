<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_login_with_email_or_phone_and_use_the_token(): void
    {
        $user = User::factory()->create(['email' => 'rahul@example.com', 'phone' => '+919876543210', 'password' => 'Secret123']);

        $byEmail = $this->postJson('/api/v1/auth/login', ['identity' => 'RAHUL@example.com', 'password' => 'Secret123', 'device_name' => 'android'])
            ->assertOk()->assertJsonPath('user.id', $user->public_id);

        $this->postJson('/api/v1/auth/login', ['identity' => '9876543210', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('user.phone', '+919876543210');

        $this->withToken($byEmail->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('name', $user->name)->assertJsonMissingPath('password');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password_or_unknown_identity_without_revealing_which(): void
    {
        User::factory()->create(['email' => 'rahul@example.com', 'password' => 'Secret123']);

        $wrong = $this->postJson('/api/v1/auth/login', ['identity' => 'rahul@example.com', 'password' => 'nope-1234'])->assertUnprocessable();
        $unknown = $this->postJson('/api/v1/auth/login', ['identity' => 'ghost@example.com', 'password' => 'Secret123'])->assertUnprocessable();

        $this->assertSame($wrong->json('errors.identity'), $unknown->json('errors.identity'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_throttled_per_identity(): void
    {
        User::factory()->create(['email' => 'rahul@example.com', 'password' => 'Secret123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['identity' => 'rahul@example.com', 'password' => 'wrong-000']);
        }

        $this->postJson('/api/v1/auth/login', ['identity' => 'rahul@example.com', 'password' => 'Secret123'])->assertStatus(429);
    }

    public function test_protected_routes_require_a_valid_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token_and_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);
        $web = $user->createToken('web')->plainTextToken;
        $android = $user->createToken('android')->plainTextToken;

        $this->withToken($web)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards(); // the test app caches the resolved guard user between requests
        $this->withToken($web)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($android)->getJson('/api/v1/auth/me')->assertOk();

        $this->withToken($android)->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_uses_sanctum_acting_as(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $user->public_id)->assertJsonPath('role', 'customer');
    }
}
