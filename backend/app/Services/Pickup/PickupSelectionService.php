<?php

namespace App\Services\Pickup;

use App\Enums\CartStatus;
use App\Enums\PickupIssueCode as Code;
use App\Enums\PickupMethod;
use App\Enums\PickupMode;
use App\Enums\PickupSelectionStatus as Status;
use App\Events\Pickup\PickupSelectionEvent;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartPickupSelection;
use App\Services\Cart\CartInspection;
use App\Services\Cart\CartOwner;
use App\Services\Cart\CartStore;
use App\Services\Cart\CartValidationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The pickup time a customer chooses for their cart (Module 28) — and the only place such a choice is written.
 *
 * A selection belongs to the cart, so it belongs to whoever owns the cart: a signed-in customer, or the holder of
 * the guest cart token. No request names a cart, a restaurant, a customer or a time: the cart is the caller's own,
 * the restaurant is the cart's, and the time comes from a slot key (or from ASAP) that the engine validates again.
 *
 * SELECTED is not RESERVED. Choosing a time holds no capacity; two customers may choose the last free place of a
 * slot, and only checkout / order creation (later modules) will give it to exactly one of them through
 * PickupCapacityRepository::reserve. Until then the selection is validated again on every read:
 *
 *   SELECTED   still valid — an ASAP selection follows the current earliest time
 *   STALE      the cart changed and the chosen time no longer fits it (or the cart itself needs attention)
 *   INVALID    the restaurant, its hours / settings, the pickup method or the slot's capacity no longer allow it
 *   EXPIRED    the time has passed or can no longer be met, or the cart has expired
 *
 * One selection per cart (unique in the database); choosing again replaces it. It is removed with the cart —
 * clearing the cart, switching restaurant and cart expiry all end it — and it moves with the cart when a guest
 * cart becomes a customer's cart at sign-in.
 */
final class PickupSelectionService
{
    public function __construct(
        private readonly CartStore $store,
        private readonly CartValidationService $validation,
        private readonly PickupAvailabilityService $availability,
    ) {}

    /**
     * The caller's cart, its pickup options and its current selection — inspected once.
     *
     * @return array{inspection: CartInspection, availability: PickupAvailability, selection: PickupSelectionView|null}|null null = no cart
     */
    public function overview(CartOwner $owner, ?PickupMethod $method = null, ?CarbonInterface $at = null): ?array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cart = $this->cartOf($owner);
        if ($cart === null) {
            return null;
        }
        $inspection = $this->validation->inspect($cart, $at);
        $availability = $this->availability->forCart($inspection, $method, $at);
        $selection = $cart->pickupSelection;

        return [
            'inspection' => $inspection,
            'availability' => $availability,
            // The selection is judged against the restaurant's offer as a whole, not against the method being browsed.
            'selection' => $selection === null ? null : $this->revalidate($selection, $inspection, $method === null ? $availability : $this->availability->forCart($inspection, null, $at), $at),
        ];
    }

    public function current(CartOwner $owner, ?CarbonInterface $at = null): ?PickupSelectionView
    {
        return $this->overview($owner, null, $at)['selection'] ?? null;
    }

    /**
     * Chooses ASAP or a scheduled slot for the caller's cart, replacing an earlier choice.
     *
     * @param  array{mode: string, method?: string|null, slot_key?: string|null, cart_version?: int|null}  $input
     *
     * @throws ApiException 404 cart_not_found; 409 cart_empty / cart_invalid / cart_version_conflict / pickup_disabled /
     *                      restaurant_unavailable / market_unavailable / restaurant_not_accepting / asap_unavailable / slot_full /
     *                      slot_expired; 410 cart_expired; 422 pickup_mode_unavailable / pickup_method_unavailable /
     *                      slot_invalid / slot_outside_horizon
     */
    public function select(CartOwner $owner, array $input, ?CarbonInterface $at = null): PickupSelectionView
    {
        $at = CarbonImmutable::instance($at ?? now());
        $mode = PickupMode::from((string) $input['mode']);
        $method = isset($input['method']) ? PickupMethod::from((string) $input['method']) : null;

        return $this->store->transaction(function () use ($owner, $input, $mode, $method, $at): PickupSelectionView {
            // The cart row is locked: a change to the cart and a choice of pickup time never interleave.
            $cart = $owner->hasIdentity() ? $this->store->lock($owner) : null;
            if ($cart === null) {
                throw ApiException::notFound('cart_not_found', 'There is no cart to choose a pickup time for.');
            }
            if (isset($input['cart_version']) && (int) $input['cart_version'] !== (int) $cart->version) {
                throw ApiException::conflict('cart_version_conflict', 'Your cart changed somewhere else. Reload it and try again.', ['current_version' => (int) $cart->version]);
            }

            $inspection = $this->validation->inspect($cart, $at);
            if ($inspection->status === CartStatus::Active && $inspection->lines === []) {
                throw ApiException::conflict('cart_empty', 'Your cart is empty. Add something before choosing a pickup time.');
            }
            $availability = $this->availability->forCart($inspection, $method, $at);
            $this->assertOpenForSelection($cart, $availability);

            $chosenMethod = $availability->method ?? throw $this->refused($cart, 409, Code::PickupDisabled, 'This restaurant does not offer pickup right now.');
            [$pickupAt, $slot] = $mode === PickupMode::Asap ? [$this->asapTime($cart, $availability), null] : $this->slotFor($cart, $availability, $input['slot_key'] ?? null, $at);

            $selection = CartPickupSelection::query()->where('cart_id', $cart->getKey())->lockForUpdate()->first() ?? (new CartPickupSelection)->forceFill(['cart_id' => $cart->getKey(), 'version' => 0]);
            $selection->forceFill([
                'mode' => $mode,
                'pickup_method' => $chosenMethod,
                'pickup_at' => $pickupAt,
                'slot_ends_at' => $slot?->endsAt,
                'slot_key' => $slot?->key,
                'slot_interval_minutes' => $slot === null ? null : (int) $availability->scheduled['interval_minutes'],
                'restaurant_timezone' => $availability->timezone,
                'cart_version' => (int) $cart->version,
                'settings_version' => $availability->settingsVersion,
                'estimated_prep_minutes' => (int) $availability->preparation?->minutes,
                'traveler_arrival_at' => $availability->journey['arrival_at'] ?? null,
                'status' => Status::Selected,
                'status_reason' => null,
                'validated_at' => $at,
                // With the cart at the latest; a scheduled choice also ends when its time comes.
                'expires_at' => $slot !== null && ($cart->expires_at === null || $slot->startsAt->lessThan($cart->expires_at)) ? $slot->startsAt : ($cart->expires_at ?? $at->addDay()),
                'version' => (int) $selection->version + 1,
            ])->save();

            event(new PickupSelectionEvent(PickupSelectionEvent::SELECTED, $cart, ['mode' => $mode->value, 'method' => $chosenMethod->value, 'journey_aware' => ($availability->journey['arrival_at'] ?? null) !== null]));

            return new PickupSelectionView($selection, Status::Selected, $pickupAt, [], $availability);
        });
    }

    /**
     * Removes the caller's selection. Safe to repeat.
     */
    public function clear(CartOwner $owner): bool
    {
        $cart = $this->cartOf($owner);
        if ($cart === null) {
            return false;
        }
        $removed = CartPickupSelection::query()->where('cart_id', $cart->getKey())->delete() > 0;
        if ($removed) {
            event(new PickupSelectionEvent(PickupSelectionEvent::CLEARED, $cart));
        }

        return $removed;
    }

    /**
     * A guest cart was merged into the customer's cart at sign-in: the guest's choice moves along unless the
     * customer's cart already has its own. It is validated against the merged cart on the next read.
     */
    public function carryOver(Cart $from, Cart $to): void
    {
        if (CartPickupSelection::query()->where('cart_id', $to->getKey())->exists()) {
            return;
        }
        CartPickupSelection::query()->where('cart_id', $from->getKey())->update(['cart_id' => $to->getKey()]);
    }

    /**
     * Validates a stored selection against what is possible now and keeps the stored status in step with the result.
     */
    public function revalidate(CartPickupSelection $selection, CartInspection $inspection, PickupAvailability $availability, ?CarbonInterface $at = null): PickupSelectionView
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cart = $inspection->cart;
        [$status, $code, $pickupAt, $slot] = $this->judge($selection, $inspection, $availability, $at);

        if ($status === Status::Selected) {
            // An ASAP choice follows the clock; its stored time is only brought up to date together with a real change.
            $selection->forceFill([
                'status' => Status::Selected,
                'status_reason' => null,
                'cart_version' => (int) $cart->version,
                'settings_version' => $availability->settingsVersion,
                'estimated_prep_minutes' => (int) $availability->preparation?->minutes,
            ]);
            if ($slot !== null) {
                $selection->forceFill(['slot_key' => $slot->key, 'slot_ends_at' => $slot->endsAt, 'slot_interval_minutes' => (int) $availability->scheduled['interval_minutes']]);
            }
            // Written only when something actually changed, so reading a valid selection costs no write.
            if ($selection->isDirty()) {
                $selection->forceFill(['pickup_at' => $pickupAt, 'validated_at' => $at, 'traveler_arrival_at' => $availability->journey['arrival_at'] ?? null])->save();
            }

            return new PickupSelectionView($selection, $status, $pickupAt, [], $availability);
        }

        $was = $selection->status;
        if ($was !== $status || $selection->status_reason !== $code->value) {
            $selection->forceFill(['status' => $status, 'status_reason' => $code->value, 'validated_at' => $at])->save();
            if ($was === Status::Selected) {
                event(new PickupSelectionEvent(PickupSelectionEvent::INVALIDATED, $cart, ['status' => $status->value, 'reason' => $code->value]));
            }
        }

        return new PickupSelectionView($selection, $status, $pickupAt, [$this->explain($code, $availability)], $availability);
    }

    /**
     * @return array{0: Status, 1: Code|null, 2: CarbonImmutable, 3: PickupSlot|null} status, reason, pickup time, slot
     */
    private function judge(CartPickupSelection $selection, CartInspection $inspection, PickupAvailability $availability, CarbonImmutable $at): array
    {
        $stored = CarbonImmutable::instance($selection->pickup_at);
        if ($inspection->status !== CartStatus::Active) {
            return [Status::Expired, Code::CartExpired, $stored, null];
        }

        $blocking = $availability->blockingIssue();
        $blockingCode = $blocking === null ? null : Code::from($blocking['code']);
        // "Nothing can be chosen right now" is decided below for the chosen option itself.
        if ($blockingCode !== null && $blockingCode !== Code::NoPickupSlots) {
            return match ($blockingCode) {
                Code::CartExpired => [Status::Expired, $blockingCode, $stored, null],
                Code::CartInvalid => [Status::Stale, $blockingCode, $stored, null],
                default => [Status::Invalid, $blockingCode, $stored, null],
            };
        }
        if (! $availability->offers($selection->pickup_method)) {
            return [Status::Invalid, Code::PickupMethodUnavailable, $stored, null];
        }

        if ($selection->mode === PickupMode::Asap) {
            return $availability->asap['available'] && $availability->asap['pickup_at'] !== null
                ? [Status::Selected, null, $availability->asap['pickup_at'], null]
                : [Status::Invalid, $availability->asap['reason'] ?? Code::AsapUnavailable, $stored, null];
        }

        if (! $availability->scheduled['enabled']) {
            return [Status::Invalid, Code::ScheduledDisabled, $stored, null];
        }
        $slot = $availability->slotAt($stored);
        if ($slot !== null) {
            return $slot->available ? [Status::Selected, null, $stored, $slot] : [Status::Invalid, Code::SlotFull, $stored, null];
        }

        // The time is no longer offered — say why.
        if ($stored->lessThanOrEqualTo($at)) {
            return [Status::Expired, Code::SlotExpired, $stored, null];
        }
        if ($availability->kitchenReadyAt !== null && $stored->lessThan($availability->kitchenReadyAt)) {
            $cartChanged = (int) $selection->cart_version !== (int) $inspection->cart->version && (int) $availability->preparation?->minutes > (int) $selection->estimated_prep_minutes;

            return match (true) {
                $cartChanged => [Status::Stale, Code::CartChanged, $stored, null],
                $availability->settingsVersion !== (int) $selection->settings_version => [Status::Invalid, Code::SlotTooEarly, $stored, null],
                // Nothing changed but the clock: the order can no longer be ready by then.
                default => [Status::Expired, Code::SlotExpired, $stored, null],
            };
        }
        $horizonEnd = $availability->scheduled['horizon_ends_at'];
        if ($horizonEnd !== null && $stored->greaterThan($horizonEnd)) {
            return [Status::Invalid, Code::SlotOutsideHorizon, $stored, null];
        }

        return [Status::Invalid, Code::SlotInvalid, $stored, null];
    }

    /**
     * Nothing may be chosen while the restaurant cannot be ordered from or the cart cannot go on.
     */
    private function assertOpenForSelection(Cart $cart, PickupAvailability $availability): void
    {
        $blocking = $availability->blockingIssue();
        if ($blocking === null) {
            return;
        }
        $code = Code::from($blocking['code']);
        if ($code === Code::NoPickupSlots) {
            return; // decided for the chosen option itself
        }

        throw match ($code) {
            Code::CartExpired => new ApiException(410, 'cart_expired', 'This cart has expired. Clear it to start a new one.', ['expired_at' => $cart->expires_at?->utc()->toIso8601String()]),
            Code::PickupMethodUnavailable => $this->refused($cart, 422, $code, $blocking['message'], $blocking['details']),
            default => $this->refused($cart, 409, $code, $blocking['message'], $blocking['details']),
        };
    }

    private function asapTime(Cart $cart, PickupAvailability $availability): CarbonImmutable
    {
        $asap = $availability->asap;
        if (! $asap['enabled']) {
            throw $this->refused($cart, 422, Code::AsapDisabled, 'This restaurant does not offer ASAP pickup.', [], 'pickup_mode_unavailable');
        }
        if (! $asap['available'] || $asap['pickup_at'] === null) {
            $reason = $asap['reason'] ?? Code::AsapUnavailable;

            throw $this->refused($cart, 409, $reason, $this->explain($reason, $availability)['message'], ['reason' => $reason->value, 'opens_next_at' => $this->detail($availability, 'opens_next_at')], 'asap_unavailable');
        }

        return $asap['pickup_at'];
    }

    /**
     * The slot a key names, if that slot may be chosen now.
     *
     * @return array{0: CarbonImmutable, 1: PickupSlot}
     */
    private function slotFor(Cart $cart, PickupAvailability $availability, mixed $key, CarbonImmutable $at): array
    {
        if (! $availability->scheduled['enabled']) {
            throw $this->refused($cart, 422, Code::ScheduledDisabled, 'This restaurant does not offer scheduled pickup.', [], 'pickup_mode_unavailable');
        }
        $named = PickupSlotKey::parse($cart->location, $key);
        if ($named === null) {
            throw $this->refused($cart, 422, Code::SlotInvalid, 'That is not a pickup time of this restaurant.');
        }
        $start = $named['start'];
        $slot = $named['interval'] === (int) $availability->scheduled['interval_minutes'] ? $availability->slotAt($start) : null;
        if ($slot !== null) {
            if (! $slot->available) {
                throw $this->refused($cart, 409, Code::SlotFull, 'That pickup time is no longer available. Please select another time.');
            }

            return [$slot->startsAt, $slot];
        }

        $horizonEnd = $availability->scheduled['horizon_ends_at'];
        throw match (true) {
            $start->lessThanOrEqualTo($at) => $this->refused($cart, 409, Code::SlotExpired, 'That pickup time has passed. Please select another time.'),
            $availability->kitchenReadyAt !== null && $start->lessThan($availability->kitchenReadyAt) => $this->refused($cart, 422, Code::SlotInvalid, 'This order cannot be ready by that time. Please select a later time.', ['reason' => Code::SlotTooEarly->value, 'earliest_pickup_at' => $availability->kitchenReadyAt->utc()->toIso8601String()]),
            $horizonEnd !== null && $start->greaterThan($horizonEnd) => $this->refused($cart, 422, Code::SlotOutsideHorizon, 'That pickup time is too far ahead for this restaurant.'),
            default => $this->refused($cart, 422, Code::SlotInvalid, 'That pickup time is not offered by this restaurant.'),
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function refused(Cart $cart, int $status, Code $code, string $message, array $details = [], ?string $errorCode = null): ApiException
    {
        event(new PickupSelectionEvent(PickupSelectionEvent::REFUSED, $cart, ['reason' => $code->value]));

        return new ApiException($status, $errorCode ?? $code->errorCode(), $message, array_filter($details, fn ($v): bool => $v !== null));
    }

    /**
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}
     */
    private function explain(Code $code, PickupAvailability $availability): array
    {
        foreach ($availability->issues as $issue) {
            if ($issue['code'] === $code->value && $issue['severity'] === PickupAvailability::BLOCKING) {
                return $issue;
            }
        }

        return PickupAvailabilityService::issue($code, match ($code) {
            Code::CartExpired => 'This cart has expired. Start a new cart to continue.',
            Code::CartInvalid => 'Your cart needs your attention before the pickup time can be confirmed.',
            Code::CartChanged => 'Your cart changed and this pickup time can no longer be met. Please choose another time.',
            Code::SlotFull => 'That pickup time is no longer available. Please select another time.',
            Code::SlotExpired => 'That pickup time has passed or can no longer be met. Please select another time.',
            Code::RestaurantClosed => 'The restaurant is closed right now, so ASAP pickup is not possible.',
            Code::ClosesTooSoon => 'The restaurant closes before this order could be ready.',
            Code::AsapDisabled => 'This restaurant no longer offers ASAP pickup.',
            Code::FullyBooked => 'ASAP pickup is fully booked right now.',
            Code::PickupMethodUnavailable => 'This pickup method is no longer available at this restaurant.',
            default => 'That pickup time is no longer offered by the restaurant. Please select another time.',
        });
    }

    private function detail(PickupAvailability $availability, string $key): mixed
    {
        foreach ($availability->issues as $issue) {
            if (isset($issue['details'][$key])) {
                return $issue['details'][$key];
            }
        }

        return null;
    }

    /**
     * The caller's ACTIVE cart with its selection — also when the cart has expired (it then says so).
     */
    private function cartOf(CartOwner $owner): ?Cart
    {
        if (! $owner->hasIdentity()) {
            return null;
        }
        $query = Cart::query()->active()->with('pickupSelection');
        $owner->constrain($query);

        return $query->first();
    }
}
