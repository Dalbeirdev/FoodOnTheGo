<?php

namespace App\Services\Sms;

use App\Exceptions\SmsDeliveryException;
use SensitiveParameter;

/**
 * Twilio Programmable Messaging (global reach). Sends the text from config otp.sms_template, which for India
 * must match the DLT-registered template word for word. The sender is a Messaging Service, or a number /
 * registered sender id.
 *
 * Needs: SMS_TWILIO_ACCOUNT_SID, SMS_TWILIO_AUTH_TOKEN and SMS_TWILIO_MESSAGING_SERVICE_SID or SMS_TWILIO_FROM.
 */
final class TwilioSmsProvider extends HttpSmsProvider
{
    public function name(): string
    {
        return 'twilio';
    }

    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void
    {
        $sid = $this->required('account_sid');
        $token = $this->required('auth_token');
        $service = (string) ($this->config['messaging_service_sid'] ?? '');
        $from = (string) ($this->config['from'] ?? '');

        if ($service === '' && $from === '') {
            throw SmsDeliveryException::notConfigured($this->name(), 'messaging_service_sid or from');
        }

        $body = strtr((string) config('otp.sms_template'), [':code' => $code, ':minutes' => (string) $minutes]);

        $this->send(fn () => $this->http()
            ->withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", array_filter([
                'To' => $phone,
                'MessagingServiceSid' => $service,
                'From' => $service === '' ? $from : '',
                'Body' => $body,
            ])));
    }
}
