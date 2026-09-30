<?php

namespace Tests\Feature\Foundation;

use App\Contracts\Sms\SmsProvider;
use App\Services\Auth\DevelopmentOtp;
use App\Services\Sms\LogSmsProvider;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SecurityBaselineTest extends TestCase
{
    public function test_log_lines_are_structured_redacted_and_carry_the_request_id(): void
    {
        $path = storage_path('logs/redaction-test.json.log');
        File::delete($path);
        config(['logging.channels.redaction_test' => ['path' => $path, 'driver' => 'single'] + config('logging.channels.structured')]);
        Context::add('request_id', 'trace-abcdef12');

        Log::channel('redaction_test')->info('login attempt with Bearer 12|tokenValueXYZ', [
            'phone' => '+919876543210',
            'otp' => '482913',
            'password' => 'Secret123',
            'card' => ['cvv' => '555', 'upi_pin' => '7777'],
            'headers' => ['authorization' => 'Bearer 99|anotherSecret'],
        ]);

        $line = trim((string) File::get($path));
        File::delete($path);
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        foreach (['482913', 'Secret123', '555', '7777', 'tokenValueXYZ', 'anotherSecret'] as $secret) {
            $this->assertStringNotContainsString($secret, $line);
        }
        $this->assertSame('+919876543210', $record['context']['phone']);
        $this->assertSame('trace-abcdef12', $record['extra']['request_id']);
        $this->assertSame('testing', $record['extra']['environment']);
        $this->assertSame('INFO', $record['level_name']);
        $this->assertArrayHasKey('datetime', $record);
    }

    public function test_sms_log_driver_never_writes_the_message_body_or_full_number(): void
    {
        Log::spy();

        $provider = app(SmsProvider::class);
        $this->assertInstanceOf(LogSmsProvider::class, $provider);
        $provider->send('+919876543210', 'Your FoodOnTheGo code is 482913');

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            $serialised = $message.json_encode($context);

            return ! str_contains($serialised, '482913') && ! str_contains($serialised, '98765') && str_ends_with($context['to'], '3210');
        });
    }

    public function test_development_otp_exists_only_in_local_and_testing_and_never_in_production(): void
    {
        $otp = app(DevelopmentOtp::class);
        $this->assertSame('123456', $otp->code());

        foreach (['production', 'staging'] as $environment) {
            config(['app.env' => $environment]);
            $this->assertNull($otp->code(), "development OTP leaked into {$environment}");

            config(['otp.development.environments' => ['local', 'testing', $environment]]);
            $this->assertFalse($otp->enabled(), "development OTP could be switched on in {$environment}");
        }

        config(['app.env' => 'local', 'otp.development.environments' => ['local', 'testing'], 'otp.development.code' => null]);
        $this->assertNull($otp->code());
    }

    public function test_env_example_contains_no_secret_values_and_env_is_ignored_by_git(): void
    {
        $example = (string) File::get(base_path('.env.example'));

        foreach (['APP_KEY', 'DB_PASSWORD', 'SMS_API_KEY', 'PAYMENT_KEY_SECRET', 'PAYMENT_WEBHOOK_SECRET', 'PLACES_API_KEY', 'ROUTING_API_KEY', 'AWS_SECRET_ACCESS_KEY', 'OTP_DEV_CODE'] as $key) {
            $this->assertMatchesRegularExpression('/^'.$key.'=$/m', $example, "{$key} must be empty in .env.example");
        }

        $this->assertMatchesRegularExpression('/^\.env$/m', (string) File::get(base_path('.gitignore')));
    }

    public function test_application_code_reads_configuration_not_env(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $this->assertDoesNotMatchRegularExpression('/\benv\(/', $file->getContents(), $file->getRelativePathname().' calls env() directly');
        }
    }

    public function test_production_defaults_are_safe(): void
    {
        $this->assertStringContainsString("'debug' => (bool) env('APP_DEBUG', false)", (string) File::get(config_path('app.php')));
        $this->assertStringContainsString("'public' => (bool) env('API_READINESS_PUBLIC', false)", (string) File::get(config_path('api.php')));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
        $this->assertTrue(config('cors.supports_credentials') === false || ! in_array('*', config('cors.allowed_origins'), true));
    }
}
