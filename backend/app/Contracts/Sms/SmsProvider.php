<?php

namespace App\Contracts\Sms;

use App\Exceptions\DeliveryException;
use SensitiveParameter;

/**
 * Delivers a one-time code to a phone. The code is always generated, hashed, expired and verified by
 * FoodOnTheGo (OtpService); a provider only carries the message. That keeps every provider interchangeable:
 * which one runs is configuration (SMS_DRIVER), and none of them can sign anybody in.
 *
 * Implementations apply timeouts, map provider errors to DeliveryException and never log the code, the
 * message body or their credentials.
 */
interface SmsProvider
{
    /**
     * Driver name as used in configuration ("log", "msg91", "twilio", "twofactor").
     */
    public function name(): string;

    /**
     * @param  string  $phone  E.164 number, e.g. +919876543210
     * @param  int  $minutes  validity told to the customer
     *
     * @throws DeliveryException
     */
    public function sendOtp(string $phone, #[SensitiveParameter] string $code, int $minutes): void;
}
