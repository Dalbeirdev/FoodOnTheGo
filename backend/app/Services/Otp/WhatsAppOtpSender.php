<?php

namespace App\Services\Otp;

use App\Exceptions\DeliveryException;
use App\Services\Sms\HttpSmsProvider;
use SensitiveParameter;

/**
 * WhatsApp delivery of a one-time code through Meta's WhatsApp Cloud API (no reseller in between).
 * Sends an approved "authentication" template with a copy-code button; the code fills the body and the
 * button. WhatsApp needs no DLT registration, and an authentication message costs a fraction of an SMS.
 *
 * It only reaches numbers that have WhatsApp. Meta accepts the request either way, so a customer without
 * WhatsApp gets the code by SMS on "resend" (OtpDelivery escalates to the next channel).
 *
 * Needs: WHATSAPP_ACCESS_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_OTP_TEMPLATE (+ WHATSAPP_OTP_TEMPLATE_LANGUAGE).
 */
final class WhatsAppOtpSender extends HttpSmsProvider
{
    public function name(): string
    {
        return 'whatsapp';
    }

    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void
    {
        $token = $this->required('access_token');
        $phoneNumberId = $this->required('phone_number_id');
        $template = $this->required('otp_template');
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        $response = $this->send(fn () => $this->http()
            ->withToken($token)
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $this->digits($phone),
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) ($this->config['otp_template_language'] ?? 'en')],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ],
                ],
            ]));

        if (! is_array($response->json('messages')) || $response->json('messages') === []) {
            throw new DeliveryException($this->name(), 'provider rejected the request');
        }
    }
}
