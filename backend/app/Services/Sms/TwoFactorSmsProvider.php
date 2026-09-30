<?php

namespace App\Services\Sms;

use App\Exceptions\SmsDeliveryException;
use SensitiveParameter;

/**
 * 2Factor.in (India). Sends our own code through their OTP route with a template approved in their panel.
 * The API key is part of the URL path, which is why errors are never reported with the URL.
 *
 * Needs: SMS_2FACTOR_API_KEY, SMS_2FACTOR_TEMPLATE.
 */
final class TwoFactorSmsProvider extends HttpSmsProvider
{
    public function name(): string
    {
        return 'twofactor';
    }

    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void
    {
        $apiKey = $this->required('api_key');
        $template = $this->required('template');

        $response = $this->send(fn () => $this->http()->get(sprintf(
            'https://2factor.in/API/V1/%s/SMS/%s/%s/%s',
            rawurlencode($apiKey),
            rawurlencode($this->digits($phone)),
            rawurlencode($code),
            rawurlencode($template),
        )));

        if ($response->json('Status') !== 'Success') {
            throw new SmsDeliveryException($this->name(), 'provider rejected the request');
        }
    }
}
