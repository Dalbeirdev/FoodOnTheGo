<?php

namespace App\Services\Sms;

use App\Exceptions\SmsDeliveryException;
use SensitiveParameter;

/**
 * MSG91 (India). Uses the OTP endpoint with our own code, so MSG91 only delivers: the code is generated and
 * verified by FoodOnTheGo. The template (with its DLT id and sender) is created in the MSG91 panel and
 * referenced here by template id.
 *
 * Needs: SMS_MSG91_AUTH_KEY, SMS_MSG91_OTP_TEMPLATE_ID.
 */
final class Msg91SmsProvider extends HttpSmsProvider
{
    public function name(): string
    {
        return 'msg91';
    }

    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void
    {
        $authKey = $this->required('auth_key');
        $templateId = $this->required('otp_template_id');

        $response = $this->send(fn () => $this->http()
            ->withHeaders(['authkey' => $authKey])
            ->post('https://control.msg91.com/api/v5/otp?'.http_build_query([
                'template_id' => $templateId,
                'mobile' => $this->digits($phone),
                'otp' => $code,
                'otp_expiry' => $minutes,
            ])));

        // MSG91 can answer HTTP 200 with {"type": "error"}.
        if ($response->json('type') !== 'success') {
            throw new SmsDeliveryException($this->name(), 'provider rejected the request');
        }
    }
}
