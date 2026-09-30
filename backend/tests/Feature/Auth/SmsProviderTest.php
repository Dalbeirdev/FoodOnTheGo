<?php

namespace Tests\Feature\Auth;

use App\Contracts\Sms\SmsProvider;
use App\Exceptions\SmsDeliveryException;
use App\Models\Customer;
use App\Models\Market;
use App\Services\Auth\DevelopmentOtp;
use App\Services\Sms\FailoverSmsProvider;
use App\Services\Sms\LogSmsProvider;
use App\Services\Sms\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The real providers are exercised against faked HTTP: what is asserted is the exact request each provider
 * documents, and that a failure never leaks the code or a credential. No message leaves the machine.
 */
class SmsProviderTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIALS = [
        'services.sms.msg91' => ['auth_key' => 'msg91-auth-key-value', 'otp_template_id' => 'tpl-64f0c0ffee'],
        'services.sms.twofactor' => ['api_key' => 'twofactor-api-key-value', 'template' => 'FOTGLogin'],
        'services.sms.twilio' => ['account_sid' => 'ACtest0000000000000000000000000000', 'auth_token' => 'twilio-auth-token-value', 'messaging_service_sid' => 'MGtest00000000000000000000000000', 'from' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(self::CREDENTIALS);
    }

    private function use(string $driver, ?string $fallback = null): SmsProvider
    {
        config(['services.sms.driver' => $driver, 'services.sms.fallback' => $fallback]);

        return app(SmsProvider::class);
    }

    public function test_msg91_sends_our_own_code_through_the_otp_endpoint(): void
    {
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'request_id' => 'abc'])]);

        $this->use('msg91')->sendOtp('+919876543210', '482913', 5);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://control.msg91.com/api/v5/otp?')
                && $request->header('authkey')[0] === 'msg91-auth-key-value'
                && $query === ['template_id' => 'tpl-64f0c0ffee', 'mobile' => '919876543210', 'otp' => '482913', 'otp_expiry' => '5'];
        });
    }

    public function test_twofactor_sends_our_own_code_with_the_approved_template(): void
    {
        Http::fake(['2factor.in/*' => Http::response(['Status' => 'Success', 'Details' => 'session'])]);

        $this->use('twofactor')->sendOtp('+919876543210', '482913', 5);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://2factor.in/API/V1/twofactor-api-key-value/SMS/919876543210/482913/FOTGLogin');
    }

    public function test_twilio_sends_the_configured_text_through_the_messaging_service(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

        $this->use('twilio')->sendOtp('+919876543210', '482913', 5);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACtest0000000000000000000000000000/Messages.json'
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('ACtest0000000000000000000000000000:twilio-auth-token-value')
                && $request->data() === [
                    'To' => '+919876543210',
                    'MessagingServiceSid' => 'MGtest00000000000000000000000000',
                    'Body' => 'Your FoodOnTheGo verification code is 482913. It expires in 5 minutes. Do not share it with anyone.',
                ];
        });

        // Without a messaging service the registered sender / number is used.
        config(['services.sms.twilio.messaging_service_sid' => null, 'services.sms.twilio.from' => 'FOTGIN']);
        $this->use('twilio')->sendOtp('+919876543210', '482913', 5);
        Http::assertSent(fn (Request $request): bool => ($request->data()['From'] ?? null) === 'FOTGIN' && ! isset($request->data()['MessagingServiceSid']));
    }

    public function test_provider_rejections_and_outages_become_safe_delivery_failures(): void
    {
        $cases = [
            'msg91' => ['control.msg91.com/*', Http::response(['type' => 'error', 'message' => 'Invalid authkey msg91-auth-key-value'])],
            'twofactor' => ['2factor.in/*', Http::response(['Status' => 'Error', 'Details' => 'Invalid API Key twofactor-api-key-value'])],
            'twilio' => ['api.twilio.com/*', Http::response(['code' => 20003, 'message' => 'Authenticate'], 401)],
        ];

        foreach ($cases as $driver => [$pattern, $response]) {
            Http::fake([$pattern => $response]);

            try {
                $this->use($driver)->sendOtp('+919876543210', '482913', 5);
                $this->fail("{$driver} should have failed");
            } catch (SmsDeliveryException $e) {
                $this->assertSame($driver, $e->provider);
                foreach (['482913', 'auth-key-value', 'api-key-value', 'auth-token-value', 'ACtest', 'http', '9876543210'] as $secret) {
                    $this->assertStringNotContainsString($secret, $e->getMessage(), "{$driver} failure leaks [{$secret}]");
                }
            }
        }
    }

    public function test_an_unreachable_provider_is_retried_once_and_then_reported_without_the_url(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out for https://2factor.in/API/V1/twofactor-api-key-value/SMS/919876543210/482913/FOTGLogin');
        });

        try {
            $this->use('twofactor')->sendOtp('+919876543210', '482913', 5);
            $this->fail('should have failed');
        } catch (SmsDeliveryException $e) {
            $this->assertSame(2, $attempts);
            $this->assertSame('SMS delivery through [twofactor] failed: provider unreachable (ConnectionException)', $e->getMessage());
        }
    }

    public function test_missing_credentials_name_the_setting_never_a_value(): void
    {
        config(['services.sms.msg91.auth_key' => null]);

        try {
            $this->use('msg91')->sendOtp('+919876543210', '482913', 5);
            $this->fail('should have failed');
        } catch (SmsDeliveryException $e) {
            $this->assertTrue($e->configuration);
            $this->assertStringContainsString('auth_key', $e->getMessage());
        }
        Http::assertNothingSent();

        $this->expectException(SmsDeliveryException::class);
        $this->use('carrier-pigeon');
    }

    public function test_the_fallback_provider_delivers_when_the_primary_cannot(): void
    {
        Http::fake([
            'control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'down'], 503),
            '2factor.in/*' => Http::response(['Status' => 'Success']),
        ]);
        Log::spy();

        $provider = $this->use('msg91', 'twofactor');
        $this->assertInstanceOf(FailoverSmsProvider::class, $provider);
        $provider->sendOtp('+919876543210', '482913', 5);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '2factor.in'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'sms.failover' && ! str_contains(json_encode($context), '482913'));
    }

    public function test_the_log_driver_sends_nothing_and_is_refused_in_production(): void
    {
        $this->assertInstanceOf(LogSmsProvider::class, $this->use('log'));
        $this->use('log')->sendOtp('+919876543210', '482913', 5);
        Http::assertNothingSent();

        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(SmsDeliveryException::class);
        $this->use('log');
    }

    public function test_with_a_real_provider_the_code_is_random_even_locally_and_reaches_only_the_provider(): void
    {
        Market::factory()->india()->create();
        config(['services.sms.driver' => 'msg91']);
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $sent[] = $query['otp'];

            return Http::response(['type' => 'success']);
        });

        $this->assertNull(app(DevelopmentOtp::class)->code(), 'the fixed development code must be off once a real SMS driver is configured');

        $first = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])->assertOk()->assertJsonPath('delivery', 'sms');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $sent[0]);
        $this->assertStringNotContainsString($sent[0], $first->getContent());
        $this->assertStringNotContainsString($sent[0], json_encode(DB::table('otp_challenges')->get()));

        // The development code does not work; the code that was actually sent does.
        if ($sent[0] !== '123456') {
            $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $first->json('challenge_id'), 'code' => '123456'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $first->json('challenge_id'), 'code' => $sent[0]])->assertCreated();
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_delivery_failure_is_a_clean_503_and_the_challenge_is_dead(): void
    {
        Market::factory()->india()->create();
        config(['services.sms.driver' => 'twilio']);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);

        $response = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])
            ->assertStatus(503)->assertJsonPath('error.code', 'otp_delivery_failed');

        $this->assertStringNotContainsString('twilio', strtolower($response->getContent()));
        $this->assertNotNull(DB::table('otp_challenges')->value('invalidated_at'));
    }

    public function test_sms_check_reports_readiness_without_printing_a_secret(): void
    {
        config(['services.sms.driver' => 'log']);
        $this->artisan('sms:check')->expectsOutputToContain('sends real SMS:     NO')->expectsOutputToContain('FIXED development code')->assertSuccessful();

        config(['services.sms.driver' => 'msg91', 'services.sms.fallback' => 'twofactor', 'services.sms.twofactor.api_key' => null]);
        $this->artisan('sms:check')
            ->expectsOutputToContain('sends real SMS:     YES')
            ->expectsOutputToContain('INCOMPLETE — set: SMS_2FACTOR_API_KEY')
            ->doesntExpectOutputToContain('msg91-auth-key-value')
            ->assertFailed();

        $status = app(SmsManager::class)->status();
        $this->assertStringNotContainsString('auth-key-value', json_encode($status));
    }

    public function test_sms_test_command_sends_one_message_and_never_prints_the_full_code(): void
    {
        Market::factory()->india()->create();
        config(['services.sms.driver' => 'twofactor']);
        Http::fake(['2factor.in/*' => Http::response(['Status' => 'Success'])]);

        $this->artisan('sms:test', ['phone' => '98765 43210'])->expectsOutputToContain('Accepted by [twofactor] for +91 ******3210')->assertSuccessful();
        Http::assertSentCount(1);

        $this->artisan('sms:test', ['phone' => '12345'])->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_no_driver_writes_the_code_or_a_credential_to_the_log(): void
    {
        $path = storage_path('logs/sms-audit-test.log');
        File::delete($path);
        config(['logging.channels.sms_audit_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'], 'logging.default' => 'sms_audit_test']);
        Log::setDefaultDriver('sms_audit_test');
        Market::factory()->india()->create();

        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error'], 500), '2factor.in/*' => Http::sequence()->push(['Status' => 'Success'])->push(['Status' => 'Error'], 500)]);
        config(['services.sms.driver' => 'msg91', 'services.sms.fallback' => 'twofactor']);
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])->assertOk();

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])->assertStatus(503);

        $log = (string) File::get($path);
        File::delete($path);

        $this->assertStringContainsString('sms.failover', $log);
        $this->assertStringContainsString('otp.delivery_failed', $log);
        foreach (['auth-key-value', 'api-key-value', '9876543210', '2factor.in/API'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertDoesNotMatchRegularExpression('/\/SMS\/\d+\/\d{6}\//', $log);
    }
}
