<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveJourney;
use App\Http\Resources\Cart\CartPresenter;
use App\Models\Cart;
use App\Models\Customer;
use App\Services\Cart\CartChange;
use App\Services\Cart\CartClaimService;
use App\Services\Cart\CartOwner;
use App\Services\Cart\CartService;
use App\Services\Cart\CartValidationService;
use App\Services\Cart\GuestCartToken;
use App\Services\Menu\MenuValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * The cart (Module 27) — one for a guest (header X-Cart-Token) or a signed-in customer, always the caller's own:
 * `cart.owner` decides whose cart a request is about, and no route takes a cart id.
 *
 * Every answer is `{cart: {...} | null}` with the cart recalculated and validated against the current menu;
 * a guest who has no token yet also receives `guest_token`, once. A client sends ids, quantities and text.
 * Prices, currencies, owners, statuses and versions are the backend's: those fields are refused, not ignored.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartValidationService $validation,
        private readonly CartPresenter $presenter,
        private readonly GuestCartToken $tokens,
    ) {}

    /**
     * GET /cart — the caller's cart with its validation state, or `cart: null`.
     */
    public function show(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $body = $this->body($this->carts->current($owner));
        if (! $owner->hasIdentity()) {
            $body['guest_token'] = $this->tokens->mint();
        }

        return response()->json($body);
    }

    /**
     * POST /cart/items — add an item (restaurant, item, selected options, quantity, special instructions and,
     * when the customer came from a journey, that journey).
     */
    public function add(Request $request): JsonResponse
    {
        $input = $request->validate([...self::itemRules(), ...self::contextRules(), ...self::forbidden()], self::messages());
        $owner = $this->writer($request);
        $this->limitGuestCartCreation($request, $owner);

        return $this->respond($this->carts->add($owner, $input, $request->header(ResolveJourney::TOKEN_HEADER)), $owner, 201);
    }

    /**
     * PATCH /cart/items/{line} — quantity, selected options and / or special instructions of one line.
     */
    public function update(Request $request, string $line): JsonResponse
    {
        $rules = self::itemRules();
        unset($rules['item_id']);
        $input = $request->validate([...$rules, 'cart_version' => ['sometimes', 'integer', 'min:0'], ...self::forbidden(), 'restaurant_id' => ['prohibited'], 'item_id' => ['prohibited']], self::messages());
        if (array_intersect(['quantity', 'selections', 'special_instructions'], array_keys($input)) === []) {
            throw new ApiException(422, 'validation_failed', 'Nothing to change: send a quantity, selections or special_instructions.', ['fields' => ['quantity' => ['required_without_all:selections,special_instructions']]]);
        }
        $owner = $this->owner($request);

        return $this->respond($this->carts->update($owner, $line, $input), $owner);
    }

    /**
     * DELETE /cart/items/{line}
     */
    public function remove(Request $request, string $line): JsonResponse
    {
        $input = $request->validate(['cart_version' => ['sometimes', 'integer', 'min:0']]);
        $owner = $this->owner($request);

        return $this->respond($this->carts->remove($owner, $line, isset($input['cart_version']) ? (int) $input['cart_version'] : null), $owner);
    }

    /**
     * DELETE /cart — the owner ends the cart (the client asks for confirmation first). Safe to repeat.
     */
    public function clear(Request $request): JsonResponse
    {
        $this->carts->clear($this->owner($request));

        return response()->json(['cart' => null]);
    }

    /**
     * POST /cart/refresh — "update my cart to the current menu": changed prices are accepted as the new baseline.
     */
    public function refresh(Request $request): JsonResponse
    {
        $input = $request->validate(['cart_version' => ['sometimes', 'integer', 'min:0'], ...self::forbidden()], self::messages());
        $owner = $this->owner($request);

        return $this->respond($this->carts->refresh($owner, isset($input['cart_version']) ? (int) $input['cart_version'] : null), $owner);
    }

    /**
     * POST /cart/switch-restaurant — the customer confirmed: replace the cart with one of another restaurant,
     * optionally starting it with the item that raised the question. `confirm` must be true.
     */
    public function switchRestaurant(Request $request): JsonResponse
    {
        $item = [];
        foreach (self::itemRules() as $field => $rule) {
            $item['item.'.$field] = $field === 'item_id' ? ['required_with:item', 'string', 'uuid'] : $rule;
        }
        $input = $request->validate([
            'confirm' => ['required', 'accepted'],
            'restaurant_id' => ['required', 'string', 'uuid'],
            'item' => ['sometimes', 'array'],
            ...$item,
            'item.price' => ['prohibited'], 'item.unit_price_minor' => ['prohibited'], 'item.currency' => ['prohibited'],
            'journey_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'cart_version' => ['sometimes', 'integer', 'min:0'],
            ...self::forbidden(),
        ], self::messages());
        $owner = $this->writer($request);
        $this->limitGuestCartCreation($request, $owner);

        return $this->respond($this->carts->switchRestaurant($owner, $input, $request->header(ResolveJourney::TOKEN_HEADER)), $owner, 201);
    }

    /**
     * PUT /cart/journey — link the cart to one of the caller's own living journeys, on purpose.
     */
    public function linkJourney(Request $request): JsonResponse
    {
        $input = $request->validate(['journey_id' => ['required', 'string', 'uuid'], 'cart_version' => ['sometimes', 'integer', 'min:0']]);
        $owner = $this->owner($request);

        return $this->respond($this->carts->linkJourney($owner, (string) $input['journey_id'], $request->header(ResolveJourney::TOKEN_HEADER), isset($input['cart_version']) ? (int) $input['cart_version'] : null), $owner);
    }

    /**
     * DELETE /cart/journey
     */
    public function unlinkJourney(Request $request): JsonResponse
    {
        $input = $request->validate(['cart_version' => ['sometimes', 'integer', 'min:0']]);
        $owner = $this->owner($request);

        return $this->respond($this->carts->unlinkJourney($owner, isset($input['cart_version']) ? (int) $input['cart_version'] : null), $owner);
    }

    /**
     * POST /cart/claim (signed-in customers) — take over the cart built before signing in. The guest cart is
     * named by its TOKEN (header X-Cart-Token), never by an id. See CartClaimService for the merge policy.
     */
    public function claim(Request $request, CartClaimService $claims): JsonResponse
    {
        $input = $request->validate(['resolution' => ['sometimes', 'nullable', Rule::in([CartClaimService::KEEP_GUEST, CartClaimService::KEEP_ACCOUNT])]]);
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $change = $claims->claim($customer, $this->tokens->parse($request->header(GuestCartToken::HEADER)), $input['resolution'] ?? null);

        return response()->json($this->body($change->cart?->fresh()) + ['claim' => $change->extra]);
    }

    /* ------------------------------------------------------------------ helpers */

    private function owner(Request $request): CartOwner
    {
        return $request->attributes->get('cartOwner');
    }

    /**
     * The owner for a request that may START a cart: somebody without a session and without a token gets one
     * minted here and is told it in the answer.
     */
    private function writer(Request $request): CartOwner
    {
        $owner = $this->owner($request);
        if ($owner->hasIdentity()) {
            return $owner;
        }
        $token = $this->tokens->mint();
        $parsed = $this->tokens->parse($token);

        return CartOwner::guest($parsed['hash'], $parsed['issued_at'], $token);
    }

    /**
     * Anonymous carts are cheap to ask for, so starting them is bounded per address.
     */
    private function limitGuestCartCreation(Request $request, CartOwner $owner): void
    {
        if (! $owner->isGuest() || $this->carts->current($owner) !== null) {
            return;
        }
        $key = 'cart-create:'.$request->ip();
        $max = max(1, (int) config('rate_limits.cart_create_guest'));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);
            throw new ApiException(429, 'rate_limited', 'Too many new carts from this address. Please try again shortly.', ['retry_after_seconds' => $seconds]);
        }
        RateLimiter::hit($key, 60);
    }

    private function respond(CartChange $change, CartOwner $owner, int $status = 200): JsonResponse
    {
        // Read again after the change: the answer is the stored cart, validated like any other read.
        $body = $this->body($change->cart?->fresh());
        if ($change->lineId !== null) {
            $body['line'] = ['id' => $change->lineId, 'merged' => $change->merged];
        }
        if ($change->replaced !== null) {
            $body['replaced'] = $change->replaced;
        }
        if ($change->extra !== []) {
            $body['result'] = $change->extra;
        }
        if ($owner->mintedToken !== null) {
            $body['guest_token'] = $owner->mintedToken;
        }

        return response()->json($body, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(?Cart $cart): array
    {
        return ['cart' => $cart === null ? null : $this->presenter->present($this->validation->inspect($cart))];
    }

    /**
     * One item: what, in which configuration, how many, with which note.
     *
     * @return array<string, mixed>
     */
    private static function itemRules(): array
    {
        return [
            'item_id' => ['required', 'string', 'uuid'],
            ...MenuValidation::selectionRules(),
            'selections.*.price_adjustment_minor' => ['prohibited'],
            'selections.*.price' => ['prohibited'],
            // The exact length is checked after cleaning (CartSignature); this only bounds the raw input.
            'special_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contextRules(): array
    {
        return [
            'restaurant_id' => ['required', 'string', 'uuid'],
            'journey_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'cart_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Never from a client: refused outright, so a payload shaped like a stored cart cannot smuggle anything in.
     *
     * @return array<string, list<string>>
     */
    private static function forbidden(): array
    {
        return array_fill_keys([
            'price', 'unit_price_minor', 'base_price_minor', 'line_subtotal_minor', 'subtotal', 'subtotal_minor', 'total', 'total_minor', 'currency',
            'customer_id', 'guest_token', 'guest_token_hash', 'owner', 'status', 'version', 'restaurant_location_id', 'validation', 'validation_status', 'issues',
            'expires_at', 'items', 'id',
        ], ['prohibited']);
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return ['prohibited' => 'This value is decided by FoodOnTheGo and cannot be sent.'];
    }
}
