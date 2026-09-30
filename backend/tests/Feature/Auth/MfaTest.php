<?php

namespace Tests\Feature\Auth;

use App\Enums\SecurityEventType;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Models\SecurityEvent;
use App\Support\Totp;
use Database\Factories\AdminUserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function code(string $secret, int $offsetSteps = 0): string
    {
        return Totp::codeAt($secret, now()->getTimestamp() + $offsetSteps * Totp::PERIOD);
    }

    /**
     * @return array{0: AdminUser, 1: string, 2: list<string>} admin, secret, recovery codes
     */
    private function enrolledAdmin(): array
    {
        $admin = AdminUser::factory()->create();
        $headers = $this->bearer($admin);

        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->json('secret');
        $codes = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $this->code($secret)], $headers)->assertOk()->json('recovery_codes');
        $this->travel(2)->minutes();

        return [$admin->fresh(), $secret, $codes];
    }

    private function login(AdminUser $admin): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD])
            ->assertOk()->assertJsonPath('mfa_required', true)->assertJsonMissingPath('token')->json('mfa_challenge');
    }

    public function test_enrolment_returns_the_secret_once_and_stores_it_encrypted(): void
    {
        $admin = AdminUser::factory()->create();
        $headers = $this->bearer($admin);

        $setup = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->assertJsonStructure(['secret', 'otpauth_uri']);
        $secret = $setup->json('secret');

        $stored = DB::table('admin_users')->where('id', $admin->id)->first();
        $this->assertNotSame($secret, $stored->mfa_secret);
        $this->assertStringNotContainsString($secret, (string) $stored->mfa_secret);
        $this->assertNull($stored->mfa_enabled_at, 'not enabled until a code is confirmed');

        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => '000000'], $headers)->assertUnprocessable()->assertJsonPath('error.code', 'mfa_code_invalid');

        $confirm = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $this->code($secret)], $headers)->assertOk()->assertJsonPath('principal.mfa_enabled', true);
        $this->assertCount(8, $confirm->json('recovery_codes'));

        $stored = DB::table('admin_users')->where('id', $admin->id)->first();
        foreach ($confirm->json('recovery_codes') as $recovery) {
            $this->assertStringNotContainsString($recovery, (string) $stored->mfa_recovery_codes);
        }
        $this->assertStringNotContainsString($secret, $this->getJson('/api/v1/auth/me', $headers)->getContent());
        $this->assertSame(1, SecurityEvent::query()->where('event', SecurityEventType::MfaEnabled)->count());
    }

    public function test_with_mfa_the_password_alone_never_yields_a_token(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $challenge = $this->login($admin);
        $before = DB::table('personal_access_tokens')->count();

        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge, 'code' => '000000'])->assertUnprocessable()->assertJsonPath('error.code', 'mfa_code_invalid');
        $this->assertSame($before, DB::table('personal_access_tokens')->count());

        $token = $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertOk()->assertJsonPath('principal.id', $admin->public_id)->json('token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();

        // The challenge is single use.
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge, 'code' => $this->code($secret, 1)])->assertUnauthorized()->assertJsonPath('error.code', 'mfa_challenge_invalid');
    }

    public function test_a_totp_code_cannot_be_used_twice(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $code = $this->code($secret);

        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $this->login($admin), 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $this->login($admin), 'code' => $code])->assertUnprocessable()->assertJsonPath('error.code', 'mfa_code_invalid');
    }

    public function test_the_challenge_expires_and_locks_after_too_many_wrong_codes(): void
    {
        [$admin] = $this->enrolledAdmin();

        $challenge = $this->login($admin);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge, 'code' => '00000'.$i])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge, 'code' => '000009'])->assertStatus(429)->assertJsonPath('error.code', 'mfa_attempts_exceeded');
        $this->assertSame(5, SecurityEvent::query()->where('event', SecurityEventType::MfaChallengeFailed)->count());

        $this->travel(2)->minutes();
        $expiring = $this->login($admin);
        $this->travel(config('auth_security.mfa.challenge_ttl_seconds') + 1)->seconds();
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $expiring, 'code' => '123456'])->assertUnauthorized();
    }

    public function test_a_challenge_issued_in_one_context_is_useless_in_the_other(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->postJson('/api/v1/auth/restaurant/mfa/verify', ['mfa_challenge' => $this->login($admin), 'code' => $this->code($secret)])
            ->assertUnauthorized()->assertJsonPath('error.code', 'mfa_challenge_invalid');
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$admin, , $recovery] = $this->enrolledAdmin();

        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $this->login($admin), 'recovery_code' => strtoupper($recovery[0])])->assertOk();
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $this->login($admin), 'recovery_code' => $recovery[0]])->assertUnprocessable();
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $this->login($admin), 'recovery_code' => $recovery[1]])->assertOk();
    }

    public function test_disabling_needs_the_password_and_a_valid_code(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $headers = $this->bearer($admin);

        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => 'wrong-password-value', 'code' => $this->code($secret)], $headers)->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => AdminUserFactory::PASSWORD, 'code' => '000000'], $headers)->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => AdminUserFactory::PASSWORD, 'email' => $admin->email], $headers)->assertUnprocessable();
        $this->assertTrue($admin->fresh()->hasMfaEnabled());

        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => AdminUserFactory::PASSWORD, 'code' => $this->code($secret)], $headers)->assertNoContent();
        $this->assertFalse($admin->fresh()->hasMfaEnabled());
        $this->assertSame(1, SecurityEvent::query()->where('event', SecurityEventType::MfaDisabled)->count());
    }

    public function test_when_mfa_is_mandatory_an_unenrolled_admin_can_only_enrol(): void
    {
        config(['auth_security.mfa.required.admin' => true]);
        $admin = AdminUser::factory()->create();

        $login = $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD])
            ->assertOk()->assertJsonPath('mfa_enrollment_required', true);
        $limited = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/markets', $limited)->assertForbidden()->assertJsonPath('error.code', 'mfa_enrollment_required');
        $this->getJson('/api/v1/auth/sessions', $limited)->assertForbidden();
        $this->getJson('/api/v1/auth/me', $limited)->assertOk();

        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $limited)->assertOk()->json('secret');
        $full = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $this->code($secret)], $limited)->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/sessions', ['Authorization' => 'Bearer '.$full])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $limited)->assertUnauthorized();

        // Mandatory MFA cannot be switched off by the account itself.
        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/auth/mfa/totp', ['password' => AdminUserFactory::PASSWORD, 'code' => $this->code($secret)], ['Authorization' => 'Bearer '.$full])
            ->assertStatus(409)->assertJsonPath('error.code', 'mfa_required');
    }

    public function test_restaurant_users_can_use_the_same_mfa_and_customers_cannot_reach_it(): void
    {
        $staff = RestaurantUser::factory()->create();
        $headers = $this->bearer($staff);
        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->json('secret');
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $this->code($secret)], $headers)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/restaurant/login', ['email' => $staff->email, 'password' => AdminUserFactory::PASSWORD])->assertOk()->assertJsonPath('mfa_required', true);

        $customer = Customer::factory()->create();
        $this->postJson('/api/v1/auth/mfa/totp/setup', [], $this->bearer($customer))->assertUnauthorized();
    }
}
