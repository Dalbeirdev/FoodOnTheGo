<?php

namespace App\Contracts\Sms;

/**
 * Outbound SMS abstraction. The live provider is chosen per environment in config/services.php (sms.driver);
 * business code depends on this contract only. Implementations must apply timeouts, map provider errors
 * and never log message bodies (they may contain one-time codes).
 */
interface SmsProvider
{
    /**
     * @param  string  $phone  E.164 number, e.g. +919876543210
     */
    public function send(string $phone, string $message): void;
}
