<?php

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Market;
use App\Models\SecurityEvent;
use App\Services\Auth\DevelopmentOtp;
use App\Services\Otp\OtpDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp first, SMS as the fallback, Truecaller one-tap — all against faked provider HTTP.
 * No message leaves the machine.
 */
class OtpChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST = '/api/v1/auth/customer/otp/request';

    /** @var list<array{channel: string, code: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Market::factory()->india()->create();
        config([
            'otp.channels' => 'whatsapp,sms',
            'services.whatsapp' => ['access_token' => 'whatsapp-access-token-value', 'phone_number_id' => '104000000000001', 'otp_template' => 'fotg_login_code', 'otp_template_language' => 'en', 'api_version' => 'v21.0', 'timeout' => 10],
            'services.sms.driver' => 'msg91',
            'services.sms.msg91' => ['auth_key' => 'msg91-auth-key-value', 'otp_template_id' => 'tpl-1'],
        ]);
    }

    /**
     * Fakes both providers and records which channel carried which code.
     */
    private function fakeProviders(bool $whatsAppWorks = true): void
    {
        $this->sent = [];
        Http::fake(function (Request $request) use ($whatsAppWorks) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                if (! $whatsAppWorks) {
                    return Http::response(['error' => ['message' => 'Invalid OAuth access token whatsapp-access-token-value', 'code' => 190]], 401);
                }
                $this->sent[] = ['channel' => 'whatsapp', 'code' => $request->data()['template']['components'][0]['parameters'][0]['text']];

                return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.X']]]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->sent[] = ['channel' => 'sms', 'code' => $query['otp']];

            return Http::response(['type' => 'success']);
        });
    }

    public function test_whatsapp_sends_the_authentication_template_with_the_code_in_body_and_copy_button(): void
    {
        $this->fakeProviders();

        $this->postJson(self::REQUEST, ['phone' => '98765 43210'])->assertOk()
            ->assertJsonPath('channel', 'whatsapp')->assertJsonPath('resend_channel', 'sms')->assertJsonPath('channels', ['whatsapp', 'sms'])->assertJsonPath('delivery', 'live');

        $code = $this->sent[0]['code'];
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v21.0/104000000000001/messages'
            && $request->header('Authorization')[0] === 'Bearer whatsapp-access-token-value'
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'to' => '919876543210',
                'type' => 'template',
                'template' => [
                    'name' => 'fotg_login_code',
                    'language' => ['code' => 'en'],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ],
                ],
            ]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame('whatsapp', DB::table('otp_challenges')->value('channel'));
    }

    public function test_the_first_code_goes_by_whatsapp_and_a_resend_moves_to_sms_with_a_new_code(): void
    {
        $this->fakeProviders();

        $first = $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertOk()->assertJsonPath('channel', 'whatsapp');
        $this->travel(31)->seconds();
        $second = $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertOk()->assertJsonPath('channel', 'sms')->assertJsonPath('resend_channel', 'sms');

        $this->assertSame(['whatsapp', 'sms'], array_column($this->sent, 'channel'));
        $this->assertNotSame($first->json('challenge_id'), $second->json('challenge_id'));

        // Only the latest code works, whatever channel it came through.
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $first->json('challenge_id'), 'code' => $this->sent[0]['code']])->assertUnprocessable();
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $second->json('challenge_id'), 'code' => $this->sent[1]['code']])->assertCreated();

        $this->assertSame(['whatsapp', 'sms'], SecurityEvent::query()->where('event', 'OTP_REQUESTED')->orderBy('id')->get()->pluck('metadata.channel')->all());
    }

    public function test_after_the_validity_window_a_new_sign_in_starts_on_the_cheapest_channel_again(): void
    {
        $this->fakeProviders();

        $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertJsonPath('channel', 'whatsapp');
        $this->travel(config('otp.ttl_seconds') + 5)->seconds();
        $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertJsonPath('channel', 'whatsapp');
    }

    public function test_the_customer_can_ask_for_sms_straight_away(): void
    {
        $this->fakeProviders();

        $this->postJson(self::REQUEST, ['phone' => '9876543210', 'channel' => 'sms'])->assertOk()->assertJsonPath('channel', 'sms');
        $this->assertSame(['sms'], array_column($this->sent, 'channel'));

        $this->postJson(self::REQUEST, ['phone' => '9123456780', 'channel' => 'telegram'])->assertUnprocessable()->assertJsonValidationErrors(['channel'], 'error.details.fields');
    }

    public function test_when_whatsapp_cannot_deliver_the_same_code_goes_by_sms_and_nothing_leaks(): void
    {
        $this->fakeProviders(whatsAppWorks: false);

        $response = $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertOk()->assertJsonPath('channel', 'sms');

        $this->assertSame(['sms'], array_column($this->sent, 'channel'));
        $this->assertStringNotContainsString('whatsapp-access-token-value', $response->getContent());
        $this->assertStringNotContainsString($this->sent[0]['code'], $response->getContent());
    }

    public function test_when_every_channel_fails_the_answer_is_a_clean_503(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertStatus(503)->assertJsonPath('error.code', 'otp_delivery_failed');
        $this->assertNotNull(DB::table('otp_challenges')->value('invalidated_at'));
    }

    public function test_whatsapp_without_credentials_is_skipped_not_fatal(): void
    {
        config(['services.whatsapp.access_token' => null]);
        $this->fakeProviders();

        $this->assertSame(['sms'], app(OtpDelivery::class)->channels());
        $this->postJson(self::REQUEST, ['phone' => '9876543210'])->assertOk()->assertJsonPath('channel', 'sms')->assertJsonPath('channels', ['sms']);
        $this->artisan('otp:check')->expectsOutputToContain('skipped, not configured: whatsapp')->assertFailed();
    }

    public function test_a_real_whatsapp_channel_turns_the_fixed_development_code_off(): void
    {
        config(['services.sms.driver' => 'log']);
        $this->assertFalse(app(OtpDelivery::class)->simulated());
        $this->assertNull(app(DevelopmentOtp::class)->code());

        config(['otp.channels' => 'sms']);
        $this->assertTrue(app(OtpDelivery::class)->simulated());
        $this->assertSame('123456', app(DevelopmentOtp::class)->code());
    }

    public function test_client_bootstrap_lists_the_available_ways_to_verify(): void
    {
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('auth.otp_channels', ['whatsapp', 'sms'])->assertJsonPath('auth.truecaller', false);

        config(['services.truecaller.client_id' => 'truecaller-client-id-value']);
        $this->getJson('/api/v1/config')->assertJsonPath('auth.truecaller', true)->assertDontSee('truecaller-client-id-value');
    }

    public function test_otp_test_command_can_target_a_channel(): void
    {
        $this->fakeProviders();

        $this->artisan('otp:test', ['phone' => '9876543210', '--channel' => 'whatsapp'])->expectsOutputToContain('Accepted on [whatsapp] for +91 ******3210')->assertSuccessful();
        $this->artisan('otp:test', ['phone' => '9876543210', '--channel' => 'pigeon'])->assertFailed();
        $this->assertSame(['whatsapp'], array_column($this->sent, 'channel'));
    }

    /* ---------------- Truecaller one-tap ---------------- */

    private function truecaller(array $profile = ['phone_number' => '919876543210', 'phone_number_verified' => true], int $tokenStatus = 200): void
    {
        config(['services.truecaller.client_id' => 'truecaller-client-id-value']);
        Http::fake([
            'oauth-account-noneu.truecaller.com/v1/token' => Http::response($tokenStatus === 200 ? ['access_token' => 'tc-access-token-value', 'token_type' => 'Bearer'] : ['error' => 'invalid_grant'], $tokenStatus),
            'oauth-account-noneu.truecaller.com/v1/userinfo' => Http::response($profile),
        ]);
    }

    private function truecallerBody(array $extra = []): array
    {
        return ['authorization_code' => 'auth-code-from-sdk', 'code_verifier' => str_repeat('v', 64), 'device_name' => 'android'] + $extra;
    }

    public function test_truecaller_signs_in_with_the_number_truecaller_vouches_for_and_sends_no_message(): void
    {
        $this->truecaller();

        $response = $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())
            ->assertCreated()->assertJsonPath('new_account', true)->assertJsonPath('principal.phone', '+919876543210')->assertJsonPath('principal.phone_verified', true);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/token')
            && $request->data() === ['grant_type' => 'authorization_code', 'client_id' => 'truecaller-client-id-value', 'code' => 'auth-code-from-sdk', 'code_verifier' => str_repeat('v', 64)]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/userinfo') && $request->header('Authorization')[0] === 'Bearer tc-access-token-value');
        Http::assertSentCount(2);

        $this->assertSame(0, DB::table('otp_challenges')->count());
        $this->assertSame('truecaller', SecurityEvent::query()->where('event', 'LOGIN_SUCCESS')->sole()->metadata['method']);
        $this->assertStringNotContainsString('tc-access-token-value', $response->getContent());

        // The same person signing in again reuses the account.
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertOk()->assertJsonPath('new_account', false);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_the_phone_number_comes_from_truecaller_never_from_the_app(): void
    {
        $this->truecaller();
        $victim = Customer::factory()->create(['phone_e164' => '+919000000001']);

        $response = $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody(['phone' => '+919000000001', 'phone_number' => '919000000001', 'customer_id' => $victim->public_id]))->assertCreated();

        $this->assertSame('+919876543210', $response->json('principal.phone'));
        $this->assertNotSame($victim->public_id, $response->json('principal.id'));
    }

    public function test_truecaller_failures_are_refused_cleanly(): void
    {
        $this->truecaller(tokenStatus: 400);
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertUnprocessable()->assertJsonPath('error.code', 'truecaller_verification_failed');

        $this->truecaller(['phone_number' => '919876543210', 'phone_number_verified' => false]);
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertUnprocessable();

        $this->truecaller(['sub' => 'x']);
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertUnprocessable();

        // A number from a country this market does not serve.
        $this->truecaller(['phone_number' => '15551234567', 'phone_number_verified' => true]);
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertUnprocessable()->assertJsonPath('error.code', 'truecaller_verification_failed');

        $this->postJson('/api/v1/auth/customer/truecaller', ['authorization_code' => 'x'])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_truecaller_respects_account_status_and_is_off_without_a_client_id(): void
    {
        $this->truecaller();
        Customer::factory()->status(CustomerStatus::Suspended)->create(['phone_e164' => '+919876543210']);
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertForbidden()->assertJsonPath('error.code', 'account_not_active');

        config(['services.truecaller.client_id' => null]);
        $this->postJson('/api/v1/auth/customer/truecaller', $this->truecallerBody())->assertNotFound()->assertJsonPath('error.code', 'truecaller_unavailable');
    }
}
