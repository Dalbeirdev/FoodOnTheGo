<?php

namespace App\Services\Auth;

use App\Contracts\Sms\SmsProvider;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\Market;
use App\Models\OtpChallenge;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * One-time-code challenges for phone sign-in.
 *
 * - The code is never stored or logged: only HMAC-SHA256(challenge id + code) keyed with the application key.
 * - A challenge expires, allows a limited number of wrong attempts and can be verified exactly once.
 * - Requesting a new code invalidates the previous one; resends respect a cooldown and an hourly cap.
 * - All timing uses the server clock. The client's countdown is decoration.
 */
final class OtpService
{
    public const PURPOSE_CUSTOMER_LOGIN = 'customer_login';

    public function __construct(
        private readonly SmsProvider $sms,
        private readonly DevelopmentOtp $developmentOtp,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function request(PhoneNumber $phone, Market $market, string $purpose = self::PURPOSE_CUSTOMER_LOGIN): OtpChallenge
    {
        $challenge = DB::transaction(function () use ($phone, $market, $purpose): OtpChallenge {
            // Serialise requests for the same phone so cooldown and hourly cap cannot be raced.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['otp:'.$phone->e164]);

            $recent = OtpChallenge::query()->where('phone_e164', $phone->e164)->where('purpose', $purpose);

            $last = (clone $recent)->latest('sent_at')->first();
            $cooldown = (int) config('otp.resend_cooldown_seconds');
            if ($last !== null && $last->sent_at->diffInSeconds(now()) < $cooldown) {
                $wait = (int) ceil($cooldown - $last->sent_at->diffInSeconds(now()));

                throw new ApiException(429, 'otp_resend_too_soon', 'Please wait a moment before requesting another code.', ['retry_after_seconds' => max(1, $wait)]);
            }

            if ((clone $recent)->where('sent_at', '>', now()->subHour())->count() >= (int) config('otp.max_sends_per_hour')) {
                throw new ApiException(429, 'otp_send_limit', 'Too many codes were requested for this number. Please try again later.', ['retry_after_seconds' => 3600]);
            }

            (clone $recent)->whereNull('verified_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

            $challenge = new OtpChallenge([
                'purpose' => $purpose,
                'phone_e164' => $phone->e164,
                'max_attempts' => (int) config('otp.max_attempts'),
                'market_id' => $market->getKey(),
                'sent_at' => now(),
                'expires_at' => now()->addSeconds((int) config('otp.ttl_seconds')),
                'code_hash' => str_repeat('0', 64),
            ]);
            $challenge->save();

            return $challenge;
        });

        $code = $this->developmentOtp->code() ?? $this->randomCode();
        $challenge->forceFill(['code_hash' => $this->hash($challenge, $code)])->save();

        try {
            $this->sms->send($phone->e164, __('auth.otp_sms', ['code' => $code, 'minutes' => (int) ceil(config('otp.ttl_seconds') / 60)]));
        } catch (Throwable $e) {
            $challenge->forceFill(['invalidated_at' => now()])->save();
            Log::warning('otp.delivery_failed', ['challenge' => $challenge->public_id, 'exception' => $e::class]);

            throw new ApiException(503, 'otp_delivery_failed', 'We could not send the code right now. Please try again in a moment.');
        }

        $this->events->record(SecurityEventType::OtpRequested, null, ['challenge' => $challenge->public_id, 'market' => $market->country_code], $phone->e164);

        return $challenge;
    }

    /**
     * Verifies a code. The challenge row is locked for the duration, so two simultaneous requests cannot
     * both succeed and a wrong attempt is always counted.
     *
     * @throws ApiException
     */
    public function verify(string $challengeId, PhoneNumber $phone, #[SensitiveParameter] string $code, string $purpose = self::PURPOSE_CUSTOMER_LOGIN): OtpChallenge
    {
        /** @var array{0: OtpChallenge|null, 1: ApiException|null} $outcome */
        $outcome = DB::transaction(function () use ($challengeId, $phone, $code, $purpose): array {
            $challenge = OtpChallenge::query()->where('public_id', $challengeId)->where('purpose', $purpose)->lockForUpdate()->first();

            if ($challenge === null || $challenge->phone_e164 !== $phone->e164 || $challenge->verified_at !== null || $challenge->invalidated_at !== null) {
                return [null, new ApiException(422, 'otp_invalid', 'This code is invalid or has expired. Request a new one.')];
            }

            if ($challenge->expires_at->isPast()) {
                return [null, new ApiException(422, 'otp_expired', 'This code has expired. Request a new one.')];
            }

            if ($challenge->attempts >= $challenge->max_attempts) {
                return [null, $this->attemptsExceeded()];
            }

            if (! hash_equals($challenge->code_hash, $this->hash($challenge, $code))) {
                $challenge->attempts++;
                $left = $challenge->max_attempts - $challenge->attempts;
                if ($left <= 0) {
                    $challenge->invalidated_at = now();
                }
                $challenge->save();

                return [null, $left <= 0
                    ? $this->attemptsExceeded()
                    : new ApiException(422, 'otp_invalid', 'That code is not correct.', ['attempts_left' => $left])];
            }

            $challenge->forceFill(['verified_at' => now()])->save();

            return [$challenge, null];
        });

        [$challenge, $failure] = $outcome;

        if ($failure !== null) {
            $this->events->record(SecurityEventType::OtpFailed, null, ['challenge' => $challengeId, 'reason' => $failure->errorCode], $phone->e164);

            throw $failure;
        }

        $this->events->record(SecurityEventType::OtpVerified, null, ['challenge' => $challengeId], $phone->e164);

        return $challenge;
    }

    private function attemptsExceeded(): ApiException
    {
        return new ApiException(429, 'otp_attempts_exceeded', 'Too many incorrect attempts. Request a new code.', ['attempts_left' => 0]);
    }

    private function randomCode(): string
    {
        $length = (int) config('otp.length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hash(OtpChallenge $challenge, #[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $challenge->public_id.'|'.$code, (string) config('app.key'));
    }
}
