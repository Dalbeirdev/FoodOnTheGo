<?php

namespace App\Http\Controllers\Api;

use App\Enums\CartStatus;
use App\Enums\PickupIssueCode;
use App\Enums\PickupMethod;
use App\Enums\PickupMode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pickup\PickupPresenter;
use App\Services\Cart\CartOwner;
use App\Services\Pickup\PickupMetrics;
use App\Services\Pickup\PickupSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pickup times for the caller's cart (Module 28): what can be chosen right now, and the choice.
 *
 * Cart-scoped like the cart itself — `cart.owner` decides whose cart a request is about (a signed-in customer or
 * the holder of the guest cart token), and no route takes a cart id. The restaurant is the cart's; the customer
 * is the caller; the clock is the server's. A client sends a mode, a pickup method and, for a scheduled time, the
 * key of a slot it was offered — never a time, a restaurant, a capacity or a validity flag: those are refused.
 *
 * A selection is a choice, not a reservation: every answer says `reserved: false`, and checkout validates again.
 */
class CartPickupController extends Controller
{
    public function __construct(
        private readonly PickupSelectionService $selections,
        private readonly PickupPresenter $presenter,
        private readonly PickupMetrics $metrics,
    ) {}

    /**
     * GET /cart/pickup-availability — ASAP, the scheduled slots, the recommendation and the current selection.
     */
    public function availability(Request $request): JsonResponse
    {
        $input = $request->validate(['method' => ['sometimes', 'string', Rule::enum(PickupMethod::class)], ...self::forbidden()], self::messages());
        $method = isset($input['method']) ? PickupMethod::from($input['method']) : null;

        $overview = $this->selections->overview($this->owner($request), $method);
        if ($overview === null) {
            throw ApiException::notFound('cart_not_found', 'There is no cart to choose a pickup time for.');
        }
        if ($overview['inspection']->status === CartStatus::Active && $overview['inspection']->lines === []) {
            throw ApiException::conflict('cart_empty', 'Your cart is empty. Add something before choosing a pickup time.');
        }
        $availability = $overview['availability'];
        if ($method !== null && ! $availability->offers($method)) {
            throw new ApiException(422, PickupIssueCode::PickupMethodUnavailable->errorCode(), 'This pickup method is not available at this restaurant.', ['method' => $method->value]);
        }
        $this->metrics->recordAvailability($availability);

        return response()->json(['pickup' => $this->presenter->availability($availability, $overview['selection'])]);
    }

    /**
     * GET /cart/pickup-selection — the choice as it stands now (validated again), or `selection: null`.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['selection' => $this->presenter->selection($this->selections->current($this->owner($request)))]);
    }

    /**
     * POST /cart/pickup-selection — choose ASAP or a scheduled slot. Choosing again replaces the earlier choice.
     */
    public function select(Request $request): JsonResponse
    {
        $input = $request->validate([
            'mode' => ['required', 'string', Rule::enum(PickupMode::class)],
            'method' => ['sometimes', 'nullable', 'string', Rule::enum(PickupMethod::class)],
            'slot_key' => ['required_if:mode,'.PickupMode::Scheduled->value, 'prohibited_if:mode,'.PickupMode::Asap->value, 'string', 'max:64'],
            'cart_version' => ['sometimes', 'integer', 'min:0'],
            ...self::forbidden(),
        ], self::messages());

        return response()->json(['selection' => $this->presenter->selection($this->selections->select($this->owner($request), $input))]);
    }

    /**
     * DELETE /cart/pickup-selection — forget the choice. Safe to repeat.
     */
    public function clear(Request $request): JsonResponse
    {
        $this->selections->clear($this->owner($request));

        return response()->json(['selection' => null]);
    }

    private function owner(Request $request): CartOwner
    {
        return $request->attributes->get('cartOwner');
    }

    /**
     * What a client may never decide: whose cart, which restaurant, which time, how much capacity, whether it is valid.
     *
     * @return array<string, list<string>>
     */
    private static function forbidden(): array
    {
        $fields = [
            'restaurant_id', 'restaurant_location_id', 'location_id', 'customer_id', 'cart_id', 'guest_token', 'owner',
            'pickup_at', 'selected_at', 'selected_time', 'time', 'starts_at', 'slot_start_at', 'slot_ends_at', 'timezone', 'restaurant_timezone',
            'status', 'valid', 'validity', 'reserved', 'capacity', 'capacity_state', 'capacity_per_slot', 'expires_at', 'version',
            'estimated_prep_minutes', 'preparation', 'traveler_arrival_at', 'now', 'client_time', 'currency',
        ];

        return array_fill_keys($fields, ['prohibited']);
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.', 'slot_key.prohibited_if' => 'An ASAP pickup has no slot.'];
    }
}
