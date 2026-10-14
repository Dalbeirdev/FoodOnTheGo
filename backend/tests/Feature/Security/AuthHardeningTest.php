<?php

namespace Tests\Feature\Security;

use App\Enums\SecurityEventType;
use App\Models\AccessToken;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Models\SecurityEvent;
use App\Support\Totp;
use Database\Factories\AdminUserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Module 39 — authentication hardening: a password or a second factor guessed slowly, from many addresses or across
 * many fresh challenges is stopped per ACCOUNT; an administrator's session ends after the idle limit; every per-person
 * limiter key names the kind of principal; one-time codes have a global and a per-number budget.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function adminLogin(string $email, string $password, int $address): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$address])->postJson('/api/v1/auth/admin/login', ['email' => $email, 'password' => $password]);
    }

    public function test_slow_password_guessing_from_many_addresses_locks_the_account_and_unknown_emails_alike(): void
    {
        $admin = AdminUser::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->adminLogin($admin->email, 'wrong-password-'.$i, $i)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
            if ($i % 3 === 0) {
                $this->travel(61)->seconds();   // the per-minute limiters stay out of the way: the lockout is what answers
            }
        }
        $this->travel(61)->seconds();
        $this->adminLogin($admin->email, AdminUserFactory::PASSWORD, 50)->assertStatus(429)->assertJsonPath('error.code', 'account_temporarily_locked');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(1, SecurityEvent::query()->where('event', SecurityEventType::AccountLocked)->count());

        // An e-mail without an account is locked the same way, so the answer does not tell which e-mails exist.
        for ($i = 1; $i <= 10; $i++) {
            $this->adminLogin('nobody@foodonthego.example', 'wrong-password-'.$i, 100 + $i)->assertUnauthorized();
            if ($i % 3 === 0) {
                $this->travel(61)->seconds();
            }
        }
        $this->travel(61)->seconds();
        $this->adminLogin('nobody@foodonthego.example', 'anything-at-all', 150)->assertStatus(429)->assertJsonPath('error.code', 'account_temporarily_locked');

        // The lock ends by itself; a success clears the count.
        $this->travel(config('auth_security.lockout.password.lock_minutes') + 1)->minutes();
        $this->adminLogin($admin->email, AdminUserFactory::PASSWORD, 51)->assertOk();
    }

    public function test_second_factor_guessing_across_fresh_challenges_locks_the_account(): void
    {
        $admin = AdminUser::factory()->create();
        $headers = $this->bearer($admin);
        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->json('secret');
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => Totp::codeAt($secret, now()->getTimestamp())], $headers)->assertOk();
        $challenge = function () use ($admin): string {
            $this->travel(61)->seconds();

            return $this->adminLogin($admin->email, AdminUserFactory::PASSWORD, 1)->assertOk()->json('mfa_challenge');
        };

        // Two challenges of five wrong codes each: the per-challenge limit alone would allow a fresh challenge forever.
        $last = null;
        for ($round = 0; $round < 2; $round++) {
            $current = $challenge();
            for ($i = 0; $i < 5; $i++) {
                $last = $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $current, 'code' => '00000'.$i]);
            }
        }
        $last->assertStatus(429)->assertJsonPath('error.code', 'account_temporarily_locked');

        // Even the right code on a new challenge is refused while the account is locked.
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge(), 'code' => Totp::codeAt($secret, now()->getTimestamp())])
            ->assertStatus(429)->assertJsonPath('error.code', 'account_temporarily_locked');
        $this->assertSame(1, SecurityEvent::query()->where('event', SecurityEventType::AccountLocked)->count());

        $this->travel(config('auth_security.lockout.mfa.lock_minutes') + 1)->minutes();
        $this->postJson('/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => $challenge(), 'code' => Totp::codeAt($secret, now()->getTimestamp())])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_idle_administrator_session_ends_and_an_active_one_does_not(): void
    {
        $admin = AdminUser::factory()->create();
        $staff = RestaurantUser::factory()->create();
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('web', [AccessToken::ACCESS], now()->addDays(2))->plainTextToken];
        $staffHeaders = ['Authorization' => 'Bearer '.$staff->createToken('web', [AccessToken::ACCESS], now()->addDays(2))->plainTextToken];
        $me = function (array $headers): int {
            $this->app['auth']->forgetGuards();

            return $this->getJson('/api/v1/auth/me', $headers)->status();
        };
        $idle = (int) config('auth_security.admin_idle_minutes');

        // Used every 50 minutes: the session lives on.
        foreach ([1, 2, 3] as $ignored) {
            $this->travel($idle - 10)->minutes();
            $this->assertSame(200, $me($adminHeaders));
        }
        // Left alone longer than the limit: the token is gone and the event recorded.
        $this->travel($idle + 1)->minutes();
        $this->assertSame(401, $me($adminHeaders));
        $this->assertSame(401, $me($adminHeaders));
        $this->assertSame(0, AccessToken::query()->where('tokenable_type', $admin->getMorphClass())->count());
        $this->assertSame(1, SecurityEvent::query()->where('event', SecurityEventType::AdminSessionIdleExpired)->count());
        // The idle rule is the administrator's: a restaurant session of the same age is still valid.
        $this->assertSame(200, $me($staffHeaders));
    }

    public function test_every_per_person_limiter_key_names_the_kind_of_principal(): void
    {
        $sameId = 7;
        $people = [
            'customer' => Customer::factory()->make()->forceFill(['id' => $sameId]),
            'restaurant' => RestaurantUser::factory()->make()->forceFill(['id' => $sameId]),
            'admin' => AdminUser::factory()->make()->forceFill(['id' => $sameId]),
        ];
        foreach (['upload', 'order-read', 'order-write', 'refund-action', 'pickup-verify', 'device-register', 'notifications-read', 'finance-action', 'admin-sensitive', 'mfa-verify', 'search'] as $name) {
            $keys = [];
            foreach ($people as $person) {
                $request = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '10.1.1.1']);
                $request->setUserResolver(fn () => $person);
                $keys[] = collect(Arr::wrap(RateLimiter::limiter($name)($request)))->map(fn ($limit) => $limit->key)->implode('|');
            }
            $this->assertCount(3, array_unique($keys), "{$name}: one id of three kinds of principal must give three keys — ".implode(', ', $keys));
        }

        // A code request without a phone number (re-authentication) and a code check without a challenge are never
        // keyed by an empty field shared by everybody.
        $reauth = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '10.1.1.1']);
        $reauth->setUserResolver(fn () => $people['customer']);
        $this->assertStringContainsString('otp-request:principal:', collect(Arr::wrap(RateLimiter::limiter('otp-request')($reauth)))->pluck('key')->implode('|'));
        $verify = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '10.2.2.2']);
        $this->assertContains('otp-verify:no-challenge:10.2.2.2', collect(Arr::wrap(RateLimiter::limiter('otp-verify')($verify)))->pluck('key')->all());
        // Keys hold hashes, never the raw phone number or e-mail address.
        $login = Request::create('/x', 'POST', ['email' => 'owner@foodonthego.example'], server: ['REMOTE_ADDR' => '10.1.1.1']);
        $this->assertStringNotContainsString('owner@', collect(Arr::wrap(RateLimiter::limiter('admin-login')($login)))->pluck('key')->implode('|'));
    }

    public function test_one_time_codes_have_a_global_budget_and_a_budget_per_number(): void
    {
        Market::factory()->india()->create();
        config(['rate_limits.otp_global_per_hour' => 3, 'otp.resend_cooldown_seconds' => 0, 'otp.max_sends_per_hour' => 100]);
        foreach (['+919876500001', '+919876500002', '+919876500003'] as $i => $phone) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.'.$i])->postJson('/api/v1/auth/customer/otp/request', ['phone' => $phone])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.9'])->postJson('/api/v1/auth/customer/otp/request', ['phone' => '+919876500004'])
            ->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
        $event = SecurityEvent::query()->where('event', SecurityEventType::OtpBudgetExceeded)->sole();
        $this->assertSame('global_per_hour', $event->metadata['budget']);
        $this->assertStringNotContainsString('9876500004', json_encode($event->toArray()));

        // A new hour; one number may receive only so many codes a day, whatever the address and the minute.
        $this->travel(61)->minutes();
        config(['rate_limits.otp_global_per_hour' => 600, 'rate_limits.otp_phone_per_day' => 2]);
        foreach ([1, 2] as $i) {
            $this->travel(2)->minutes();
            $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.'.$i])->postJson('/api/v1/auth/customer/otp/request', ['phone' => '+919876500005'])->assertOk();
        }
        $this->travel(2)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.9'])->postJson('/api/v1/auth/customer/otp/request', ['phone' => '+919876500005'])->assertStatus(429);
        $this->assertSame(['global_per_hour', 'number_per_day'], SecurityEvent::query()->where('event', SecurityEventType::OtpBudgetExceeded)->orderBy('id')->get()->map(fn ($e) => $e->metadata['budget'])->all());
    }

    public function test_password_reset_requests_are_limited_per_email_whatever_the_address(): void
    {
        $admin = AdminUser::factory()->create();
        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.5.0.'.$i])->postJson('/api/v1/auth/admin/password/forgot', ['email' => $admin->email])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.5.0.9'])->postJson('/api/v1/auth/admin/password/forgot', ['email' => $admin->email])->assertStatus(429);
        // Another e-mail from the same last address is still answered.
        $this->withServerVariables(['REMOTE_ADDR' => '10.5.0.9'])->postJson('/api/v1/auth/admin/password/forgot', ['email' => 'other@foodonthego.example'])->assertOk();
    }
}
