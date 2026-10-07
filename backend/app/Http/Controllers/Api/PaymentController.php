<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\PaymentPresenter;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's payments (Module 30). The work is PaymentService's; this class only says what may be sent.
 *
 * No request carries an amount, a currency, a status or an owner — a request that tries is refused before it
 * reaches the service — and none carries payment credentials: what a customer types into the provider's interface
 * goes to the provider and never passes through FoodOnTheGo.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentPresenter $presenter,
    ) {}

    /**
     * Starts paying a confirmed checkout — or returns the attempt that is already open for it.
     */
    public function store(Request $request, string $checkout): JsonResponse
    {
        $input = $request->validate([
            // The price the customer saw. A payment is never started for another one.
            'pricing_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
            // How the customer means to pay — a type the provider's interface may start with. Never credentials.
            'payment_method' => ['sometimes', 'nullable', 'array:type'],
            'payment_method.type' => ['sometimes', 'nullable', 'string', 'max:16'],
            ...self::forbidden(),
        ], self::messages());
        [$attempt, $created] = $this->payments->start($this->customer($request), $checkout, $input);

        return $this->answer($request, $attempt, $created ? 201 : 200);
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        return $this->answer($request, $this->payments->show($this->customer($request), $payment));
    }

    /**
     * The device reports how the provider's interface ended. What it sends is evidence to be verified — a reference
     * and the provider's proof — and never a status: "success" here only means "please verify".
     */
    public function complete(Request $request, string $payment): JsonResponse
    {
        $input = $request->validate([
            'outcome' => ['required', 'string', 'in:success,failed,cancelled'],
            'provider_payment_reference' => ['required_if:outcome,success', 'nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_]{6,64}\z/'],
            'provider_signature' => ['required_if:outcome,success', 'nullable', 'string', 'max:128', 'regex:/\A[0-9a-fA-F]{32,128}\z/'],
            // What the provider's interface said went wrong — kept for the message, trusted for nothing.
            'provider_error' => ['sometimes', 'nullable', 'array:code,reason'],
            'provider_error.code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'provider_error.reason' => ['sometimes', 'nullable', 'string', 'max:64'],
            ...self::forbidden(),
        ], self::messages());

        return $this->answer($request, $this->payments->complete($this->customer($request), $payment, $input));
    }

    private function answer(Request $request, PaymentAttempt $attempt, int $status = 200): JsonResponse
    {
        return response()->json(['payment' => $this->presenter->present($attempt, $this->customer($request))], $status);
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }

    /**
     * @return array<string, list<string>>
     */
    private static function forbidden(): array
    {
        $fields = [
            // money: the checkout's total, never accepted from a client
            'amount', 'amount_minor', 'payment_amount', 'total', 'total_minor', 'subtotal', 'discount', 'tax', 'fee', 'fees', 'price', 'currency',
            // identity and state: the payment's own, decided by the server and the provider
            'status', 'payment_status', 'verification_status', 'verified', 'verified_at', 'paid', 'captured', 'capture', 'fulfillment_status', 'failure', 'failure_code', 'failure_category',
            'customer_id', 'customer', 'checkout_id', 'checkout', 'cart_id', 'restaurant_id', 'location_id', 'market_id', 'attempt_number', 'expires_at', 'now', 'client_time',
            'provider', 'provider_mode', 'provider_status', 'provider_order_reference', 'order_reference', 'order_id', 'receipt', 'key', 'key_id', 'key_secret', 'webhook_secret', 'secret',
            // credentials: typed into the provider's interface only
            'card', 'card_number', 'number', 'pan', 'cvv', 'cvc', 'expiry', 'expiry_month', 'expiry_year', 'upi_pin', 'pin', 'upi_id', 'vpa', 'password', 'bank_password', 'netbanking_password',
            'otp', 'bank_account', 'account_number', 'ifsc', 'cash_at_pickup',
            'payment_method.card_number', 'payment_method.number', 'payment_method.cvv', 'payment_method.cvc', 'payment_method.upi_pin', 'payment_method.pin', 'payment_method.password', 'payment_method.amount',
        ];

        return array_fill_keys($fields, ['prohibited']);
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'prohibited' => 'The :attribute field is decided by the server and cannot be sent.',
            'payment_method.array' => 'A payment method is a type — never card or bank details.',
        ];
    }
}
