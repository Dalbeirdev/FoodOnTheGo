<?php

namespace App\Services\Auth;

use App\Enums\CustomerStatus;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\Market;
use App\Models\OtpChallenge;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Turns a verified phone challenge into a signed-in customer: the existing account for that number, or a
 * new one. Uniqueness of the normalised number is guaranteed by the database, not by a prior lookup.
 */
final class CustomerLoginService
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $events,
    ) {}

    /**
     * @return array{0: Customer, 1: NewAccessToken, 2: bool} customer, token, whether the account is new
     */
    public function signIn(OtpChallenge $verified, PhoneNumber $phone, Market $market, ?string $device): array
    {
        [$customer, $created] = DB::transaction(function () use ($phone, $market): array {
            // ON CONFLICT DO NOTHING: two simultaneous first sign-ins for one number yield one row.
            $created = DB::table('customers')->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'phone_e164' => $phone->e164,
                'status' => CustomerStatus::Active->value,
                'market_id' => $market->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            return [Customer::query()->where('phone_e164', $phone->e164)->lockForUpdate()->firstOrFail(), $created];
        });

        if (! $customer->canAuthenticate()) {
            $this->events->record(SecurityEventType::LoginFailed, $customer, ['reason' => 'account_'.strtolower($customer->status->value)]);

            throw new ApiException(403, 'account_not_active', 'This account cannot sign in right now. Please contact support.');
        }

        $customer->forceFill(['phone_verified_at' => $verified->verified_at, 'last_login_at' => now()])->save();
        $token = $this->tokens->issue($customer, $device);

        $this->events->record(SecurityEventType::LoginSuccess, $customer, ['method' => 'phone_otp', 'new_account' => $created, 'session' => $token->accessToken->public_id]);

        return [$customer, $token, $created];
    }
}
