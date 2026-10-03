<?php

namespace App\Services\Customer;

use App\Auth\Principal;
use App\Enums\CustomerStatus;
use App\Enums\PhoneChangeStatus;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\CustomerPhoneChange;
use App\Models\Market;
use App\Models\OtpChallenge;
use App\Services\Audit\AuditRecorder;
use App\Services\Auth\AccountStatusService;
use App\Services\Auth\OtpService;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Auth\TokenIssuer;
use App\Services\Market\MarketContext;
use App\Support\PhoneNumber;
use App\Support\PlainText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sensitive account actions of a customer (Module 25), built on the Module 21 identity:
 *
 *  - re-authentication: a one-time code to the account's own phone, verified on the current session;
 *  - account deletion request: recorded, the account becomes RESTRICTED (can still sign in and read its data,
 *    later modules refuse new orders by policy), sessions stay; erasure / anonymisation is a privacy process of
 *    a later module — final legal behaviour PENDING BUSINESS & LEGAL APPROVAL; nothing is hard-deleted;
 *  - phone change: the NEW number is proven by a code sent to it, uniqueness is checked twice, the identity row
 *    moves to the new number, every other session is revoked, a security event and an audit entry are written.
 *    The phone is never changed by the profile endpoint.
 */
final class CustomerAccountService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SecurityEventRecorder $events,
        private readonly AuditRecorder $audit,
        private readonly AccountStatusService $status,
        private readonly TokenIssuer $tokens,
        private readonly MarketContext $markets,
        private readonly RecentAuthentication $recent,
    ) {}

    /* ------------------------------------------------------------------ re-authentication */

    /**
     * @return array{0: OtpChallenge, 1: PhoneNumber}
     */
    public function requestReauth(Customer $customer, ?string $channel): array
    {
        $market = $this->marketOf($customer);
        $phone = $this->phone($customer->phone_e164, $market);
        $challenge = $this->otp->request($phone, $market, OtpService::PURPOSE_CUSTOMER_REAUTH, $channel);

        return [$challenge, $phone];
    }

    public function verifyReauth(Customer $customer, string $challengeId, #[\SensitiveParameter] string $code, Request $request): void
    {
        $phone = $this->phone($customer->phone_e164, $this->marketOf($customer));
        $this->otp->verify($challengeId, $phone, $code, OtpService::PURPOSE_CUSTOMER_REAUTH);
        $this->recent->mark($request);
        $this->events->record(SecurityEventType::Reauthenticated, $customer, ['method' => 'phone_otp']);
    }

    /* ------------------------------------------------------------------ deletion request */

    public function requestDeletion(Customer $customer, ?string $reason, Principal $actor): Customer
    {
        return DB::transaction(function () use ($customer, $reason, $actor): Customer {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->deletion_requested_at !== null) {
                return $locked;
            }
            $from = $locked->status;
            $locked->forceFill(['deletion_requested_at' => now(), 'deletion_reason' => PlainText::clean($reason, 'reason'), 'version' => (int) $locked->version + 1])->save();
            if ($locked->status === CustomerStatus::Active) {
                $this->status->change($locked, CustomerStatus::Restricted, $actor, 'Account deletion requested by the customer');
            }
            $this->events->record(SecurityEventType::AccountDeletionRequested, $locked, ['status' => $locked->status->value]);
            $this->audit->record('customer.deletion_requested', $locked, $actor, ['status' => ['from' => $from->value, 'to' => $locked->status->value]], null, $locked->market_id === null ? null : (int) $locked->market_id);

            return $locked->refresh();
        });
    }

    /* ------------------------------------------------------------------ phone change */

    /**
     * @return array{0: CustomerPhoneChange, 1: OtpChallenge, 2: PhoneNumber}
     */
    public function requestPhoneChange(Customer $customer, string $phoneInput, ?string $country, ?string $channel): array
    {
        $market = $country !== null ? $this->markets->current($country) : $this->marketOf($customer);
        try {
            $phone = PhoneNumber::parse($phoneInput, $market);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => [$e->getMessage()]]);
        }
        if ($phone->e164 === $customer->phone_e164) {
            throw ValidationException::withMessages(['phone' => ['This is already the number of your account.']]);
        }
        $this->assertPhoneFree($phone, $customer);

        $challenge = $this->otp->request($phone, $market, OtpService::PURPOSE_PHONE_CHANGE, $channel);
        $change = DB::transaction(function () use ($customer, $phone, $challenge): CustomerPhoneChange {
            CustomerPhoneChange::query()->where('customer_id', $customer->getKey())->where('status', PhoneChangeStatus::Pending->value)->update(['status' => PhoneChangeStatus::Cancelled->value]);
            $change = (new CustomerPhoneChange)->forceFill(['customer_id' => $customer->getKey(), 'new_phone_e164' => $phone->e164, 'challenge_public_id' => $challenge->public_id, 'status' => PhoneChangeStatus::Pending, 'expires_at' => $challenge->expires_at]);
            $change->save();

            return $change->refresh();
        });
        $this->events->record(SecurityEventType::PhoneChangeRequested, $customer, ['change' => $change->public_id, 'new_phone_masked' => $phone->masked()]);

        return [$change, $challenge, $phone];
    }

    public function verifyPhoneChange(Customer $customer, string $challengeId, #[\SensitiveParameter] string $code, Request $request, Principal $actor): Customer
    {
        $change = CustomerPhoneChange::query()->where('customer_id', $customer->getKey())->where('challenge_public_id', $challengeId)->where('status', PhoneChangeStatus::Pending->value)->first();
        if ($change === null) {
            throw new ApiException(422, 'phone_change_invalid', 'This code is invalid or has expired. Request a new one.');
        }
        if ($change->expires_at->isPast()) {
            $change->forceFill(['status' => PhoneChangeStatus::Expired])->save();
            throw new ApiException(422, 'phone_change_invalid', 'This code has expired. Request a new one.');
        }
        $market = $this->marketOf($customer);
        $phone = $this->phone($change->new_phone_e164, $market);
        $this->otp->verify($challengeId, $phone, $code, OtpService::PURPOSE_PHONE_CHANGE);

        return DB::transaction(function () use ($customer, $change, $phone, $request, $actor): Customer {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPhoneFree($phone, $locked);
            $from = $locked->phone_e164;
            $locked->forceFill(['phone_e164' => $phone->e164, 'phone_verified_at' => now(), 'version' => (int) $locked->version + 1])->save();
            $change->forceFill(['status' => PhoneChangeStatus::Completed, 'completed_at' => now()])->save();

            $current = $request->user()?->currentAccessToken();
            $revoked = $this->tokens->revokeAll($locked, $current instanceof AccessToken ? (int) $current->getKey() : null);
            $fromMasked = PhoneNumber::mask($from, $locked->market?->phone_country_code);
            $this->events->record(SecurityEventType::PhoneChanged, $locked, ['from_masked' => $fromMasked, 'to_masked' => $phone->masked(), 'sessions_revoked' => $revoked]);
            $this->audit->record('customer.phone_changed', $locked, $actor, ['phone' => ['from' => $fromMasked, 'to' => $phone->masked()]], null, $locked->market_id === null ? null : (int) $locked->market_id);

            return $locked->refresh();
        });
    }

    /* ------------------------------------------------------------------ helpers */

    private function marketOf(Customer $customer): Market
    {
        $customer->loadMissing('market');

        return $customer->market ?? $this->markets->current(null);
    }

    private function phone(string $e164, Market $market): PhoneNumber
    {
        try {
            return PhoneNumber::parse($e164, $market);
        } catch (InvalidArgumentException) {
            return PhoneNumber::fromE164($e164, $market->phone_country_code);
        }
    }

    /**
     * The same answer whether the number belongs to someone else or cannot be used: nothing about other
     * accounts is revealed.
     */
    private function assertPhoneFree(PhoneNumber $phone, Customer $customer): void
    {
        if (Customer::query()->where('phone_e164', $phone->e164)->whereKeyNot($customer->getKey())->exists()) {
            throw new ApiException(409, 'phone_in_use', 'This number cannot be used for your account.');
        }
    }
}
