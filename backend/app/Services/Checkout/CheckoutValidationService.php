<?php

namespace App\Services\Checkout;

use App\Enums\CartIssueCode;
use App\Enums\CartIssueSeverity;
use App\Enums\CartStatus;
use App\Enums\CartValidationStatus;
use App\Enums\CheckoutIssueCode as Code;
use App\Enums\PickupIssueCode;
use App\Enums\PickupSelectionStatus;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Services\Cart\CartInspection;
use App\Services\Cart\CartOwner;
use App\Services\Cart\CartService;
use App\Services\Pickup\PickupSelectionService;
use App\Services\Pickup\PickupSelectionView;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The final validation before a checkout exists, is confirmed or is paid (Module 29). It decides nothing new: it
 * asks the authorities of the earlier modules once more, at this moment, in the order "cheap and decisive first":
 *
 *   customer's own ACTIVE cart → not expired, not empty → restaurant, organization, market and menu (Module 23 / 22 /
 *   24 through the cart inspection of Module 27) → every item, option and price against the current menu → the
 *   pickup selection against the restaurant's hours, settings, capacity and the clock (Module 28).
 *
 * Prices are never taken from the cart's snapshot: the inspection prices every line from today's menu. When only
 * prices (or names) changed, the cart is brought up to date here and the customer is TOLD what changed — the new
 * total is shown before anything is paid; the old price is never kept. Anything else that is wrong blocks the
 * checkout and says where the customer can put it right (cart or pickup step).
 */
final class CheckoutValidationService
{
    public function __construct(
        private readonly PickupSelectionService $pickup,
        private readonly CartService $carts,
    ) {}

    /**
     * @param  bool  $acceptPriceChanges  bring a cart whose only problem is changed prices up to date (creating a
     *                                    checkout does; judging an existing one does not — it must go stale instead)
     */
    public function validate(Customer $customer, ?CarbonInterface $at = null, bool $acceptPriceChanges = false): CheckoutContext
    {
        $at = CarbonImmutable::instance($at ?? now());
        $owner = CartOwner::customer($customer);
        $overview = $this->pickup->overview($owner, null, $at);
        if ($overview === null) {
            return new CheckoutContext(null, null, null, null, [CheckoutContext::issue(Code::CartMissing, 'There is no cart to check out.')]);
        }

        $inspection = $overview['inspection'];
        $changes = [];
        if ($acceptPriceChanges && $this->onlyNeedsReview($inspection)) {
            $changes = $this->priceChanges($inspection);
            try {
                $this->carts->refresh($owner, (int) $inspection->cart->version);
            } catch (ApiException) {
                // The cart moved under us: the validation below reports what is true now.
            }
            $overview = $this->pickup->overview($owner, null, $at) ?? $overview;
            $inspection = $overview['inspection'];
        }

        $issues = $this->cartIssues($inspection);
        $selection = $overview['selection'];
        if ($issues === []) {
            array_push($issues, ...$this->pickupIssues($selection));
        }

        return new CheckoutContext($inspection->cart, $inspection, $overview['availability'], $selection, $issues, $changes);
    }

    /**
     * @return list<array{code: string, message: string, recover: string, details: array<string, mixed>}>
     */
    private function cartIssues(CartInspection $inspection): array
    {
        if ($inspection->status === CartStatus::Expired) {
            return [CheckoutContext::issue(Code::CartExpired, 'This cart has expired. Start a new cart to continue.')];
        }
        if ($inspection->status !== CartStatus::Active) {
            return [CheckoutContext::issue(Code::CartMissing, 'There is no cart to check out.')];
        }
        if ($inspection->lines === []) {
            return [CheckoutContext::issue(Code::CartEmpty, 'Your cart is empty.')];
        }

        $issues = [];
        foreach ($inspection->allIssues() as $issue) {
            $code = CartIssueCode::tryFrom((string) $issue['code']);
            $severity = (string) $issue['severity'];
            if ($code === null || $severity === CartIssueSeverity::Info->value) {
                continue;
            }
            $details = array_filter(['cart_issue' => $code->value, 'line_id' => $issue['line_id'] ?? null], fn ($v): bool => $v !== null);
            $issues[] = match (true) {
                $code === CartIssueCode::RestaurantNotAccepting => CheckoutContext::issue(Code::RestaurantNotAccepting, (string) $issue['message'], $details),
                in_array($code, [CartIssueCode::RestaurantUnavailable, CartIssueCode::MenuUnavailable], true) => CheckoutContext::issue(Code::RestaurantUnavailable, (string) $issue['message'], $details),
                $code === CartIssueCode::CurrencyMismatch => CheckoutContext::issue(Code::CurrencyMismatch, (string) $issue['message'], $details),
                in_array($code, [CartIssueCode::ItemSoldOut, CartIssueCode::ItemTemporarilyUnavailable, CartIssueCode::ItemDisabled, CartIssueCode::ItemRemoved], true) => CheckoutContext::issue(Code::ItemUnavailable, (string) $issue['message'], $details),
                $code === CartIssueCode::PriceChanged => CheckoutContext::issue(Code::PriceChanged, (string) $issue['message'], $details),
                default => CheckoutContext::issue(Code::CartInvalid, (string) $issue['message'], $details),
            };
        }
        if ($issues === [] && ! $inspection->canProceed()) {
            $issues[] = CheckoutContext::issue(Code::CartInvalid, 'Your cart needs your attention before checkout.', ['cart_validation' => $inspection->validation->value]);
        }

        return $issues;
    }

    /**
     * @return list<array{code: string, message: string, recover: string, details: array<string, mixed>}>
     */
    private function pickupIssues(?PickupSelectionView $selection): array
    {
        if ($selection === null) {
            return [CheckoutContext::issue(Code::PickupRequired, 'Choose a pickup time before checkout.')];
        }
        if ($selection->valid()) {
            return [];
        }
        $reason = (string) ($selection->issues[0]['code'] ?? '');
        $message = (string) ($selection->issues[0]['message'] ?? 'Your pickup time is no longer available. Please choose another.');

        return [match (true) {
            $reason === PickupIssueCode::SlotFull->value || $reason === PickupIssueCode::FullyBooked->value => CheckoutContext::issue(Code::PickupSlotFull, $message, ['pickup_issue' => $reason]),
            $selection->status === PickupSelectionStatus::Expired => CheckoutContext::issue(Code::PickupSelectionExpired, $message, ['pickup_issue' => $reason]),
            default => CheckoutContext::issue(Code::PickupInvalid, $message, ['pickup_issue' => $reason]),
        }];
    }

    /** Nothing blocks the cart, but something must be seen by the customer first (a changed price). */
    private function onlyNeedsReview(CartInspection $inspection): bool
    {
        return $inspection->status === CartStatus::Active && $inspection->lines !== [] && $inspection->validation === CartValidationStatus::NeedsReview;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function priceChanges(CartInspection $inspection): array
    {
        $changes = [];
        foreach ($inspection->lines as $line) {
            if ($line->priceChanged()) {
                $changes[] = [
                    'code' => Code::PriceChanged->value,
                    'line_id' => (string) $line->line->public_id,
                    'name' => (string) $line->item->name,
                    'quantity' => (int) $line->line->quantity,
                    'previous_unit_price' => ['amount' => (int) $line->line->unit_price_minor, 'currency' => (string) $line->line->currency],
                    'unit_price' => ['amount' => $line->unitPriceMinor(), 'currency' => (string) $line->line->currency],
                ];
            }
        }

        return $changes;
    }
}
