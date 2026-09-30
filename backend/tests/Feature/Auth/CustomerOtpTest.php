<?php

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Enums\SecurityEventType;
use App\Models\Customer;
use App\Models\Market;
use App\Models\OtpChallenge;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerOtpTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST = '/api/v1/auth/customer/otp/request';

    private const VERIFY = '/api/v1/auth/customer/otp/verify';

    protected function setUp(): void
    {
        parent::setUp();

        Market::factory()->india()->create();
    }

    private function requestCode(string $phone = '98765 43210'): TestResponse
    {
        return $this->postJson(self::REQUEST, ['phone' => $phone]);
    }

    private function verify(string $challengeId, string $code = '123456', string $phone = '9876543210'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson(self::VERIFY, ['phone' => $phone, 'challenge_id' => $challengeId, 'code' => $code, 'device_name' => 'android']);
    }

    public function test_requesting_a_code_returns_a_challenge_and_never_the_code(): void
    {
        $response = $this->requestCode()->assertOk()
            ->assertJsonStructure(['challenge_id', 'phone_masked', 'expires_at', 'resend_available_at', 'attempts_allowed', 'server_time', 'delivery'])
            ->assertJsonPath('phone_masked', '+91 ******3210')
            ->assertJsonPath('attempts_allowed', 5);

        $this->assertStringNotContainsString('123456', $response->getContent());
        $this->assertStringNotContainsString('9876543210', $response->getContent());
    }

    public function test_the_code_is_stored_only_as_a_keyed_hash(): void
    {
        $this->requestCode()->assertOk();

        $row = DB::table('otp_challenges')->first();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->code_hash);
        $this->assertStringNotContainsString('123456', json_encode($row));
        $this->assertNotSame(hash('sha256', '123456'), $row->code_hash, 'an unkeyed hash of a six digit code could be brute forced');
    }

    public function test_the_response_is_identical_for_a_new_and_an_existing_customer(): void
    {
        Customer::factory()->create(['phone_e164' => '+919876543210']);

        $existing = $this->requestCode('9876543210')->assertOk()->json();
        $new = $this->requestCode('9123456780')->assertOk()->json();

        $this->assertSame(array_keys($existing), array_keys($new));
        $this->assertSame($existing['delivery'], $new['delivery']);
    }

    public function test_a_new_phone_creates_one_customer_and_signs_them_in(): void
    {
        $challenge = $this->requestCode()->json('challenge_id');

        $response = $this->verify($challenge)->assertCreated()
            ->assertJsonPath('new_account', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('principal.principal_type', 'CUSTOMER')
            ->assertJsonPath('principal.phone', '+919876543210')
            ->assertJsonPath('principal.phone_verified', true)
            ->assertJsonPath('principal.profile_complete', false)
            ->assertJsonPath('principal.market', 'IN');

        $customer = Customer::query()->sole();
        $this->assertSame('+919876543210', $customer->phone_e164);
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertSame($customer->public_id, $response->json('principal.id'));
        $this->assertNotNull($response->json('expires_at'));

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk()->assertJsonPath('id', $customer->public_id);
    }

    public function test_an_existing_customer_is_reused_whatever_format_the_phone_is_typed_in(): void
    {
        $customer = Customer::factory()->create(['phone_e164' => '+919876543210', 'name' => 'Rahul Sharma']);

        foreach (['098765 43210', '+91 98765 43210', '91 9876543210'] as $format) {
            $this->travel(1)->minutes();
            $challenge = $this->requestCode($format)->assertOk()->json('challenge_id');
            $this->verify($challenge, '123456', $format)->assertOk()->assertJsonPath('new_account', false)->assertJsonPath('principal.id', $customer->public_id);
        }

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_wrong_code_is_counted_and_the_challenge_locks_after_the_attempt_limit(): void
    {
        $challenge = $this->requestCode()->json('challenge_id');

        foreach ([4, 3, 2, 1] as $left) {
            $this->verify($challenge, '000000')->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid')->assertJsonPath('error.details.attempts_left', $left);
        }
        $this->verify($challenge, '000000')->assertStatus(429)->assertJsonPath('error.code', 'otp_attempts_exceeded');

        // Even the right code no longer works: the challenge is dead, a new one is needed.
        $this->verify($challenge, '123456')->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid');
        $this->assertSame(0, Customer::query()->count());
        $this->assertNotNull(OtpChallenge::query()->sole()->invalidated_at);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $challenge = $this->requestCode()->json('challenge_id');

        $this->travel(config('otp.ttl_seconds') + 1)->seconds();

        $this->verify($challenge)->assertUnprocessable()->assertJsonPath('error.code', 'otp_expired');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_verified_code_cannot_be_replayed(): void
    {
        $challenge = $this->requestCode()->json('challenge_id');

        $this->verify($challenge)->assertCreated();
        $this->verify($challenge)->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid');

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_a_challenge_only_works_for_the_phone_it_was_issued_to(): void
    {
        $challenge = $this->requestCode('9876543210')->json('challenge_id');

        $this->verify($challenge, '123456', '9123456780')->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_resend_respects_the_server_cooldown_and_invalidates_the_previous_code(): void
    {
        $first = $this->requestCode()->json('challenge_id');

        $this->requestCode()->assertStatus(429)->assertJsonPath('error.code', 'otp_resend_too_soon')->assertJsonPath('error.details.retry_after_seconds', 30);

        $this->travel(31)->seconds();
        $second = $this->requestCode()->assertOk()->json('challenge_id');

        $this->assertNotSame($first, $second);
        $this->verify($first)->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid');
        $this->verify($second)->assertCreated();
    }

    public function test_sends_per_phone_are_capped_per_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->requestCode()->assertOk();
            $this->travel(61)->seconds();
        }

        $this->requestCode()->assertStatus(429)->assertJsonPath('error.code', 'otp_send_limit');

        $this->travel(61)->minutes();
        $this->requestCode()->assertOk();
    }

    public function test_request_rate_limit_applies_per_phone_and_per_address(): void
    {
        config(['otp.resend_cooldown_seconds' => 0, 'otp.max_sends_per_hour' => 100]);

        for ($i = 0; $i < 3; $i++) {
            $this->requestCode('9876543210')->assertOk();
        }
        $this->requestCode('9876543210')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        // Other phones from the same address still work until the per-address limit (10 per minute).
        $accepted = 3;
        for ($i = 0; $i < 10; $i++) {
            $accepted += $this->requestCode('912345678'.$i)->status() === 200 ? 1 : 0;
        }
        $this->assertLessThanOrEqual(10, $accepted, 'one address cannot request codes for unlimited phones');
        $this->requestCode('9123456799')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_verify_rate_limit_stops_guessing_across_challenges(): void
    {
        config(['rate_limits.otp_verify_ip' => 4]);
        $challenge = $this->requestCode()->json('challenge_id');

        for ($i = 0; $i < 4; $i++) {
            $this->verify($challenge, '000000');
        }

        $this->verify($challenge, '123456')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_invalid_input_is_a_field_error(): void
    {
        $this->postJson(self::REQUEST, [])->assertUnprocessable()->assertJsonValidationErrors(['phone'], 'error.details.fields');
        $this->requestCode('12345')->assertUnprocessable()->assertJsonValidationErrors(['phone'], 'error.details.fields');
        $this->postJson(self::REQUEST, ['phone' => '9876543210', 'country' => 'india'])->assertUnprocessable()->assertJsonValidationErrors(['country'], 'error.details.fields');
        $this->postJson(self::VERIFY, ['phone' => '9876543210', 'challenge_id' => 'not-a-uuid', 'code' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['challenge_id', 'code'], 'error.details.fields');
    }

    public function test_a_market_that_does_not_serve_customers_cannot_be_used_to_sign_in(): void
    {
        Market::factory()->create(['country_code' => 'AE', 'phone_country_code' => '+971', 'phone_national_pattern' => '^5[0-9]{8}$']);

        $this->postJson(self::REQUEST, ['phone' => '050 123 4567', 'country' => 'AE'])->assertNotFound()->assertJsonPath('error.code', 'market_unavailable');
    }

    public function test_an_international_number_works_once_its_market_is_active_through_data_only(): void
    {
        Market::factory()->active()->create(['country_code' => 'AE', 'phone_country_code' => '+971', 'phone_national_pattern' => '^5[0-9]{8}$', 'phone_trunk_prefix' => '0']);

        $challenge = $this->postJson(self::REQUEST, ['phone' => '050 123 4567', 'country' => 'AE'])->assertOk()->assertJsonPath('phone_masked', '+971 *****4567')->json('challenge_id');

        $this->postJson(self::VERIFY, ['phone' => '+971 50 123 4567', 'country' => 'AE', 'challenge_id' => $challenge, 'code' => '123456'])
            ->assertCreated()->assertJsonPath('principal.phone', '+971501234567')->assertJsonPath('principal.market', 'AE');
    }

    public function test_suspended_and_deactivated_customers_cannot_sign_in_but_restricted_ones_can(): void
    {
        foreach ([[CustomerStatus::Suspended, 403], [CustomerStatus::Deactivated, 403], [CustomerStatus::Restricted, 200]] as $i => [$status, $expected]) {
            $phone = '987650000'.$i;
            Customer::factory()->status($status)->create(['phone_e164' => '+91'.$phone]);

            $challenge = $this->requestCode($phone)->json('challenge_id');
            $response = $this->verify($challenge, '123456', $phone)->assertStatus($expected);

            if ($expected === 403) {
                $response->assertJsonPath('error.code', 'account_not_active')->assertJsonMissingPath('token');
            } else {
                $response->assertJsonPath('principal.status', 'RESTRICTED');
            }
        }

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_security_events_are_recorded_without_the_code_or_the_phone_number(): void
    {
        $challenge = $this->requestCode()->json('challenge_id');
        $this->verify($challenge, '000000');
        $this->verify($challenge);

        $events = SecurityEvent::query()->orderBy('id')->get();
        $this->assertSame(
            [SecurityEventType::OtpRequested, SecurityEventType::OtpFailed, SecurityEventType::OtpVerified, SecurityEventType::LoginSuccess],
            $events->pluck('event')->all(),
        );

        $serialised = json_encode(DB::table('security_events')->get());
        foreach (['123456', '000000', '9876543210'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised);
        }
        $this->assertSame(hash('sha256', '+919876543210'), $events[0]->identifier_hash);
        $this->assertSame('customer', $events[3]->principal_type);
    }

    public function test_profile_completion_updates_only_the_allowed_fields(): void
    {
        $challenge = $this->requestCode()->json('challenge_id');
        $token = ['Authorization' => 'Bearer '.$this->verify($challenge)->json('token')];
        $this->app['auth']->forgetGuards();

        $this->patchJson('/api/v1/auth/customer/profile', [
            'name' => '  Asha Verma ', 'email' => 'Asha@Example.com', 'accept_terms' => true,
            'status' => 'SUSPENDED', 'phone_e164' => '+911111111111', 'phone_verified_at' => null, 'principal_type' => 'ADMIN_USER', 'is_admin' => true, 'market_id' => 999,
        ], $token)->assertOk()
            ->assertJsonPath('name', 'Asha Verma')
            ->assertJsonPath('email', 'asha@example.com')
            ->assertJsonPath('profile_complete', true)
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('phone', '+919876543210')
            ->assertJsonPath('principal_type', 'CUSTOMER');

        $this->patchJson('/api/v1/auth/customer/profile', ['email' => 'not-an-email'], $token)->assertUnprocessable();
    }
}
