<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\PaymentMethodSummaryResource;
use App\Models\Customer;
use App\Services\Customer\PaymentMethodReferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Saved payment methods of the authenticated customer (Module 25) — provider references and safe display
 * metadata only. There is no route that creates a method: attaching one is the payment provider's secure flow
 * (Module 30). Any credential-like field in a request is refused outright.
 */
class PaymentMethodController extends Controller
{
    private const NEVER = ['card_number', 'pan', 'cvv', 'cvc', 'upi_pin', 'pin', 'account_number', 'ifsc', 'password', 'otp', 'token'];

    public function __construct(private readonly PaymentMethodReferenceService $methods) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return PaymentMethodSummaryResource::collection($this->methods->list($customer));
    }

    public function makeDefault(Request $request, string $paymentMethod): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $this->refuseCredentials($request);
        $this->methods->makeDefault($customer, $this->methods->find($customer, $paymentMethod), $customer);

        return PaymentMethodSummaryResource::collection($this->methods->list($customer));
    }

    /**
     * Removes the saved method from the customer's list. Until Module 30 this changes the FoodOnTheGo
     * reference only; the provider-side detach follows with the real integration.
     */
    public function destroy(Request $request, string $paymentMethod): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $this->methods->revoke($customer, $this->methods->find($customer, $paymentMethod), $customer);

        return PaymentMethodSummaryResource::collection($this->methods->list($customer));
    }

    private function refuseCredentials(Request $request): void
    {
        $request->validate(array_fill_keys(self::NEVER, ['prohibited']), ['prohibited' => 'FoodOnTheGo never receives payment credentials. Payment methods are added through the payment provider.']);
    }
}
