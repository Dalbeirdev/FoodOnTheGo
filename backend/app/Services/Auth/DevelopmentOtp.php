<?php

namespace App\Services\Auth;

/**
 * The fixed one-time code used by local builds and automated tests. It exists only when ALL hold: the
 * environment is listed in config otp.development.environments, a code is configured, and the SMS driver is
 * "log" (no message is sent). As soon as a real SMS provider is configured, codes are random everywhere.
 * "production" can never be on that list — it is rejected here regardless of configuration.
 */
final class DevelopmentOtp
{
    public function code(): ?string
    {
        $environment = (string) config('app.env');
        $allowed = array_diff((array) config('otp.development.environments'), ['production', 'staging']);
        $code = config('otp.development.code');

        if (! in_array($environment, $allowed, true) || ! is_string($code) || $code === '' || config('services.sms.driver') !== 'log') {
            return null;
        }

        return $code;
    }

    public function enabled(): bool
    {
        return $this->code() !== null;
    }
}
