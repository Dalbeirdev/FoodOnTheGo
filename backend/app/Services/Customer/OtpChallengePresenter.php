<?php

namespace App\Services\Customer;

use App\Models\OtpChallenge;
use App\Services\Auth\DevelopmentOtp;
use App\Services\Auth\OtpService;
use App\Services\Otp\OtpDelivery;
use App\Support\PhoneNumber;

/**
 * The same answer the sign-in code request gives (Module 21), so the apps reuse their OTP screen for
 * re-authentication and phone changes. Never contains the code.
 */
final class OtpChallengePresenter
{
    public function __construct(private readonly OtpService $otp, private readonly OtpDelivery $delivery, private readonly DevelopmentOtp $development) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function present(OtpChallenge $challenge, PhoneNumber $phone, array $extra = []): array
    {
        return [
            'challenge_id' => $challenge->public_id,
            'phone_masked' => $phone->masked(),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_at' => $challenge->sent_at->addSeconds((int) config('otp.resend_cooldown_seconds'))->toIso8601String(),
            'attempts_allowed' => $challenge->max_attempts,
            'server_time' => now()->toIso8601String(),
            'delivery' => $this->development->enabled() ? 'development' : 'live',
            'channel' => $challenge->channel,
            'resend_channel' => $this->delivery->nextChannel($this->otp->lastSendNumber + 1),
            'channels' => $this->delivery->channels(),
            ...$extra,
        ];
    }
}
