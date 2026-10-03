<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerProfileResource;
use App\Models\Customer;
use App\Services\Customer\CustomerAccountService;
use App\Services\Customer\OtpChallengePresenter;
use App\Services\Customer\RecentAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sensitive account actions of the authenticated customer (Module 25): re-authentication by a code to the
 * account's own phone, and the account deletion request (recorded, account RESTRICTED, nothing hard-deleted).
 * Nothing here is reachable without a token, and nothing happens on a GET.
 */
class AccountController extends Controller
{
    public function __construct(private readonly CustomerAccountService $account, private readonly RecentAuthentication $recent, private readonly OtpChallengePresenter $challenges) {}

    public function reauth(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate(['channel' => ['sometimes', 'nullable', 'string', 'in:sms,whatsapp']]);
        [$challenge, $phone] = $this->account->requestReauth($customer, $input['channel'] ?? null);

        return response()->json($this->challenges->present($challenge, $phone, ['purpose' => 'reauth']));
    }

    public function verifyReauth(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate(['challenge_id' => ['required', 'string', 'uuid'], 'code' => ['required', 'string', 'min:4', 'max:10']]);
        $this->account->verifyReauth($customer, $input['challenge_id'], $input['code'], $request);

        return response()->json(['reauthenticated' => true, 'valid_until' => now()->addMinutes((int) config('customer.reauth.window_minutes'))->toIso8601String()]);
    }

    /**
     * Requires recent authentication (403 reauthentication_required otherwise). Idempotent: a second request
     * changes nothing and answers with the same state.
     */
    public function requestDeletion(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate(['confirm' => ['required', 'accepted'], 'reason' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('customer.limits.deletion_reason')]]);
        $this->recent->assert($request);
        $updated = $this->account->requestDeletion($customer, $input['reason'] ?? null, $customer);

        return (new CustomerProfileResource($updated))->additional(['deletion' => [
            'status' => 'requested',
            'account_status' => $updated->status->value,
            'note' => 'Your request has been recorded and your account is restricted. Erasure follows the retention and legal rules, which are pending final approval; support will contact you if anything is needed.',
        ]])->response();
    }
}
