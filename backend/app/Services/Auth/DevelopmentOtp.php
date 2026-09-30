<?php

namespace App\Services\Auth;

/**
 * The fixed one-time code used by local builds and automated tests. It exists only when BOTH hold:
 * the environment is listed in config otp.development.environments AND a code is configured.
 * "production" can never be on that list — it is rejected here regardless of configuration.
 */
final class DevelopmentOtp
{
    public function code(): ?string
    {
        $environment = (string) config('app.env');
        $allowed = array_diff((array) config('otp.development.environments'), ['production', 'staging']);
        $code = config('otp.development.code');

        if (! in_array($environment, $allowed, true) || ! is_string($code) || $code === '') {
            return null;
        }

        return $code;
    }

    public function enabled(): bool
    {
        return $this->code() !== null;
    }
}
