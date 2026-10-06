<?php

namespace App\Services\Cart;

use App\Enums\CartCloseReason;
use App\Events\Cart\CartClaimed;
use App\Events\Cart\CartMergeConflict;
use App\Events\Cart\CartMerged;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Guest → customer: what happens to the cart a guest built when they sign in. ONE policy, decided here, so the
 * web and the Android app cannot differ.
 *
 *   1  the guest has no cart, an empty one or an expired one  →  nothing to claim; the customer keeps what they had
 *   2  the customer has no cart (or an empty / expired one)   →  the guest cart BECOMES the customer's cart
 *   3  both have items from the SAME restaurant location      →  merged into the customer's cart:
 *        · a guest line with the same configuration (signature) raises that line's quantity — up to the item's
 *          maximum; a capped quantity is reported in `adjusted`, never applied silently
 *        · every other guest line moves over as its own line
 *        · the guest cart's journey link is taken over when it is still alive
 *   4  both have items from DIFFERENT restaurants             →  409 cart_merge_conflict, both carts untouched,
 *        until the customer chooses: keep_guest (the account's cart is closed) or keep_account (the guest cart is)
 *
 * The cart is claimed with the guest TOKEN — presented by the device that holds it, in the session of the
 * customer who just signed in — never with a cart id. The token is spent by the claim: it cannot address any
 * cart afterwards, so nothing of the guest session survives into the authenticated one. All of it is one
 * transaction: there is no half-merged cart.
 */
final class CartClaimService
{
    public const KEEP_GUEST = 'keep_guest';

    public const KEEP_ACCOUNT = 'keep_account';

    public function __construct(
        private readonly CartStore $store,
        private readonly CartValidationService $validation,
        private readonly CartJourneyLink $journeys,
    ) {}

    /**
     * @param  array{hash: string, issued_at: CarbonImmutable}|null  $token  the parsed guest token, when one was sent
     */
    public function claim(Customer $customer, ?array $token, ?string $resolution = null): CartChange
    {
        return $this->store->transaction(function () use ($customer, $token, $resolution): CartChange {
            // Always the customer's cart first, then the guest's: one locking order, no deadlocks.
            $mine = $this->store->lock(CartOwner::customer($customer));
            $guest = $token === null ? null : $this->store->lock(CartOwner::guest($token['hash'], $token['issued_at']));

            if ($guest === null) {
                return new CartChange($mine, extra: ['outcome' => 'nothing_to_claim']);
            }
            $hash = (string) $guest->guest_token_hash;

            if ($guest->isExpired() || ! $guest->items()->exists()) {
                $this->end($guest, $guest->isExpired() ? CartCloseReason::Expired : CartCloseReason::DiscardedAtSignIn, $hash);

                return new CartChange($mine, extra: ['outcome' => 'nothing_to_claim']);
            }

            $mineHasItems = $mine !== null && ! $mine->isExpired() && $mine->items()->exists();
            if (! $mineHasItems) {
                if ($mine !== null) {
                    $mine->close($mine->isExpired() ? CartCloseReason::Expired : CartCloseReason::ReplacedAtSignIn);
                    $mine->save();
                }

                return new CartChange($this->adopt($guest, $customer, $hash), extra: ['outcome' => 'claimed']);
            }

            if ((int) $mine->restaurant_location_id === (int) $guest->restaurant_location_id) {
                return $this->merge($mine, $guest, $hash);
            }

            return match ($resolution) {
                self::KEEP_GUEST => $this->keepGuest($mine, $guest, $customer, $hash),
                self::KEEP_ACCOUNT => $this->keepAccount($mine, $guest, $hash),
                default => throw $this->conflict($mine, $guest),
            };
        });
    }

    private function adopt(Cart $guest, Customer $customer, string $hash): Cart
    {
        $guest->customer_id = $customer->getKey();
        $guest->guest_token_hash = null;
        $guest->spent_token_hash = $hash;
        $this->store->touch($guest);
        DB::afterCommit(fn () => event(new CartClaimed($guest)));

        return $guest;
    }

    private function merge(Cart $mine, Cart $guest, string $hash): CartChange
    {
        $guest->load(['items.menuItem']);
        $moved = 0;
        $combined = 0;
        $adjusted = [];
        foreach ($guest->items as $line) {
            /** @var CartItem $line */
            $twin = CartItem::query()->where('cart_id', $mine->getKey())->where('signature', $line->signature)->first();
            if ($twin === null) {
                $line->cart_id = $mine->getKey();
                $line->save();
                $moved++;

                continue;
            }
            $wanted = (int) $twin->quantity + (int) $line->quantity;
            $max = (int) $line->menuItem->max_quantity;
            $twin->quantity = min($wanted, max($max, (int) $twin->quantity));
            $twin->save();
            $line->delete();
            $combined++;
            if ((int) $twin->quantity < $wanted) {
                $adjusted[] = ['line_id' => $twin->public_id, 'name' => (string) $twin->item_name, 'requested_quantity' => $wanted, 'quantity' => (int) $twin->quantity, 'max_quantity' => $max];
            }
        }

        if ($guest->journey_public_id !== null && $this->journeys->isLive($guest)) {
            $mine->journey_public_id = $guest->journey_public_id;
            $mine->journey_summary = $guest->journey_summary;
        }
        $this->end($guest, CartCloseReason::Merged, $hash);
        $this->store->touch($mine);
        DB::afterCommit(fn () => event(new CartMerged($mine, ['lines_moved' => $moved, 'lines_combined' => $combined, 'quantities_capped' => count($adjusted)])));

        return new CartChange($mine, extra: ['outcome' => 'merged', 'lines_moved' => $moved, 'lines_combined' => $combined, 'adjusted' => $adjusted]);
    }

    private function keepGuest(Cart $mine, Cart $guest, Customer $customer, string $hash): CartChange
    {
        $mine->close(CartCloseReason::ReplacedAtSignIn);
        $mine->save();

        return new CartChange($this->adopt($guest, $customer, $hash), extra: ['outcome' => 'kept_guest']);
    }

    private function keepAccount(Cart $mine, Cart $guest, string $hash): CartChange
    {
        $this->end($guest, CartCloseReason::DiscardedAtSignIn, $hash);

        return new CartChange($mine, extra: ['outcome' => 'kept_account']);
    }

    /**
     * Closes the guest cart and spends its token.
     */
    private function end(Cart $guest, CartCloseReason $reason, string $hash): void
    {
        $guest->close($reason);
        $guest->guest_token_hash = null;
        $guest->spent_token_hash = $hash;
        $guest->save();
    }

    private function conflict(Cart $mine, Cart $guest): ApiException
    {
        $details = [
            'guest' => $this->validation->inspect($guest)->summary(),
            'account' => $this->validation->inspect($mine)->summary(),
            'resolutions' => [self::KEEP_GUEST, self::KEEP_ACCOUNT],
        ];
        event(new CartMergeConflict($mine));

        return ApiException::conflict('cart_merge_conflict', 'You have a cart from before you signed in and a cart in your account, from two different restaurants. Choose which one to keep.', $details);
    }
}
