<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsProvider;
use App\Exceptions\SmsDeliveryException;

/**
 * Builds the configured SMS provider (config/services.php → sms). Adding a provider = one driver class and
 * one line here; nothing else in the application changes.
 */
final class SmsManager
{
    public const DRIVERS = ['log', 'msg91', 'twofactor', 'twilio'];

    public function provider(): SmsProvider
    {
        $primary = $this->driver((string) config('services.sms.driver'));
        $fallback = (string) config('services.sms.fallback');

        return $fallback !== '' && $fallback !== $primary->name() ? new FailoverSmsProvider($primary, $this->driver($fallback)) : $primary;
    }

    public function driver(string $name): SmsProvider
    {
        $timeout = (int) config('services.sms.timeout', 10);

        return match ($name) {
            'log' => $this->logDriver(),
            'msg91' => new Msg91SmsProvider((array) config('services.sms.msg91'), $timeout),
            'twofactor' => new TwoFactorSmsProvider((array) config('services.sms.twofactor'), $timeout),
            'twilio' => new TwilioSmsProvider((array) config('services.sms.twilio'), $timeout),
            default => throw SmsDeliveryException::notConfigured($name === '' ? 'none' : $name, 'SMS_DRIVER must be one of '.implode(', ', self::DRIVERS)),
        };
    }

    /**
     * What is configured, without any secret value: used by `php artisan sms:check`.
     *
     * @return array{driver: string, fallback: string|null, sends_real_sms: bool, ready: bool, missing: list<string>}
     */
    public function status(): array
    {
        $driver = (string) config('services.sms.driver');
        $fallback = (string) config('services.sms.fallback') ?: null;
        $missing = [];

        foreach (array_filter([$driver, $fallback]) as $name) {
            foreach ($this->requiredKeys($name) as $key => $env) {
                if ((string) config("services.sms.{$name}.{$key}") === '') {
                    $missing[] = $env;
                }
            }
        }
        if ($driver === 'twilio' && (string) config('services.sms.twilio.messaging_service_sid') === '' && (string) config('services.sms.twilio.from') === '') {
            $missing[] = 'SMS_TWILIO_MESSAGING_SERVICE_SID or SMS_TWILIO_FROM';
        }
        if (! in_array($driver, self::DRIVERS, true)) {
            $missing[] = 'SMS_DRIVER';
        }
        if ($driver === 'log' && app()->isProduction()) {
            $missing[] = 'SMS_DRIVER (the log driver sends nothing and is refused in production)';
        }

        return ['driver' => $driver, 'fallback' => $fallback, 'sends_real_sms' => $driver !== 'log', 'ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * @return array<string, string> config key → environment variable
     */
    private function requiredKeys(string $driver): array
    {
        return match ($driver) {
            'msg91' => ['auth_key' => 'SMS_MSG91_AUTH_KEY', 'otp_template_id' => 'SMS_MSG91_OTP_TEMPLATE_ID'],
            'twofactor' => ['api_key' => 'SMS_2FACTOR_API_KEY', 'template' => 'SMS_2FACTOR_TEMPLATE'],
            'twilio' => ['account_sid' => 'SMS_TWILIO_ACCOUNT_SID', 'auth_token' => 'SMS_TWILIO_AUTH_TOKEN'],
            default => [],
        };
    }

    /**
     * The log driver sends nothing: in production that would silently lock every customer out, so it is refused.
     */
    private function logDriver(): SmsProvider
    {
        if (app()->isProduction()) {
            throw SmsDeliveryException::notConfigured('log', 'a real SMS_DRIVER is required in production');
        }

        return new LogSmsProvider;
    }
}
