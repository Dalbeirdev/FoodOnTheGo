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
 * Moving the account to a new phone number (Module 25): request (recent authentication required) sends a code
 * to the NEW number; verify proves it and updates the identity, revoking every other session.
 */
class PhoneChangeController extends Controller
{
    public function __construct(private readonly CustomerAccountService $account, private readonly RecentAuthentication $recent, private readonly OtpChallengePresenter $challenges) {}

    public function request(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'channel' => ['sometimes', 'nullable', 'string', 'in:sms,whatsapp'],
        ]);
        $this->recent->assert($request);
        [$change, $challenge, $phone] = $this->account->requestPhoneChange($customer, $input['phone'], $input['country'] ?? null, $input['channel'] ?? null);

        return response()->json($this->challenges->present($challenge, $phone, ['purpose' => 'phone_change', 'change_id' => $change->public_id]));
    }

    public function verify(Request $request): CustomerProfileResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate(['challenge_id' => ['required', 'string', 'uuid'], 'code' => ['required', 'string', 'min:4', 'max:10']]);

        return new CustomerProfileResource($this->account->verifyPhoneChange($customer, $input['challenge_id'], $input['code'], $request, $customer));
    }
}
