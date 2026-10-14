<?php

namespace App\Http\Controllers\Api;

use App\Enums\LegalDocumentType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Checkout\CheckoutPresenter;
use App\Models\Customer;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\CheckoutView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Checkout for a signed-in customer (Module 29). Every route is the caller's own: a checkout is found only for the
 * customer it belongs to, and the cart, the restaurant and the currency are never named by a request.
 *
 * What a client may send: a promotion code, the legal documents it showed (type + version), how the customer intends
 * to pay (a method type or one of their saved references), an order note, and the price fingerprint it displayed.
 * What it may not send: any amount, a currency, an owner, a status, a time — and never card or bank credentials.
 * Such fields are refused (422) and never read.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkouts,
        private readonly CheckoutPresenter $presenter,
    ) {}

    public function open(Request $request): JsonResponse
    {
        $input = $request->validate([
            'promotion_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cart_version' => ['sometimes', 'integer', 'min:0'],
            ...self::forbidden(),
        ], self::messages());
        // Module 39: a code sent with "open" is a guess like one sent to /promotion — the same tight limit applies to it
        // (it had only the looser checkout-write limit).
        if (($input['promotion_code'] ?? null) !== null && $input['promotion_code'] !== '') {
            $key = 'promotion-code-open:'.$this->customer($request)->getKey();
            if (RateLimiter::tooManyAttempts($key, (int) config('rate_limits.promotion_apply'))) {
                throw new ApiException(429, 'too_many_requests', 'Too many attempts. Please wait a minute and try again.');
            }
            RateLimiter::hit($key, 60);
        }
        $view = $this->checkouts->open($this->customer($request), $input);

        return $this->answer($request, $view, $view->created ? 201 : 200);
    }

    public function show(Request $request, string $checkout): JsonResponse
    {
        return $this->answer($request, $this->checkouts->show($this->customer($request), $checkout));
    }

    public function applyPromotion(Request $request, string $checkout): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'min:1', 'max:40'], ...self::forbidden()], self::messages());

        return $this->answer($request, $this->checkouts->setPromotion($this->customer($request), $checkout, (string) $input['code']));
    }

    public function removePromotion(Request $request, string $checkout): JsonResponse
    {
        return $this->answer($request, $this->checkouts->setPromotion($this->customer($request), $checkout, null));
    }

    public function confirm(Request $request, string $checkout): JsonResponse
    {
        $input = $request->validate([
            'pricing_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
            'accepted_documents' => ['present', 'array', 'max:10'],
            'accepted_documents.*' => ['array:type,version'],
            'accepted_documents.*.type' => ['required', 'string', Rule::enum(LegalDocumentType::class)],
            'accepted_documents.*.version' => ['required', 'string', 'max:40'],
            // How the customer INTENDS to pay — a type or one of their saved references. Never credentials.
            'payment_method' => ['sometimes', 'nullable', 'array:type,saved_method_id'],
            'payment_method.type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'payment_method.saved_method_id' => ['sometimes', 'nullable', 'uuid'],
            'order_note' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('checkout.order_note.max_length')],
            ...self::forbidden(),
        ], self::messages());

        return $this->answer($request, $this->checkouts->confirm($this->customer($request), $checkout, $input, (string) Context::get('request_id')));
    }

    public function cancel(Request $request, string $checkout): JsonResponse
    {
        return $this->answer($request, $this->checkouts->cancel($this->customer($request), $checkout));
    }

    private function answer(Request $request, CheckoutView $view, int $status = 200): JsonResponse
    {
        return response()->json(['checkout' => $this->presenter->present($view, $this->customer($request))], $status);
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }

    /**
     * Everything the server decides — and everything that must never travel through this API.
     *
     * @return array<string, list<string>>
     */
    private static function forbidden(): array
    {
        $fields = [
            // money: calculated by the backend, never accepted
            'subtotal', 'subtotal_minor', 'items_subtotal', 'discount', 'discount_minor', 'discounts', 'tax', 'tax_minor', 'taxes', 'tax_total', 'fee', 'fees', 'fee_minor', 'fee_total',
            'total', 'total_minor', 'grand_total', 'amount', 'amount_minor', 'payment_amount', 'price', 'prices', 'pricing', 'currency', 'promotion_value', 'promotion_discount',
            // identity, state and time: the session's own
            'customer_id', 'customer', 'cart_id', 'restaurant_id', 'restaurant_location_id', 'location_id', 'market_id', 'pickup_selection_id', 'status', 'ready', 'expires_at', 'version',
            'pickup_at', 'slot', 'slot_key', 'capacity', 'capacity_units', 'hold', 'now', 'client_time', 'phone', 'phone_e164', 'payment_required',
            // credentials: never through this API
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
            'payment_method.array' => 'A payment method is a type or a saved reference — never card or bank details.',
        ];
    }
}
