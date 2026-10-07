<?php

namespace App\Services\Checkout;

use App\Enums\CartStatus;
use App\Enums\CheckoutIssueCode as Code;
use App\Enums\CheckoutStatus as Status;
use App\Enums\PaymentMethodStatus;
use App\Enums\PromotionRejection;
use App\Events\Checkout\CheckoutEvent;
use App\Exceptions\ApiException;
use App\Http\Resources\Cart\CartPresenter;
use App\Models\Cart;
use App\Models\CheckoutLegalAcceptance;
use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Market;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Services\Cart\CartLineInspection;
use App\Services\Cart\CartOwner;
use App\Services\Cart\CartStore;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The checkout domain (Module 29): the one place where a signed-in customer's cart and pickup selection become a
 * short-lived, server-priced CHECKOUT SESSION that a payment can later be started for.
 *
 *   open            validate everything again → price → create the session (DRAFT), or hand back the live one when
 *                   nothing changed (safe to repeat, from any device)
 *   show            the session as it stands NOW: it is judged again on every read and turns STALE or EXPIRED
 *   promotion       apply / remove one promotion code → priced again by the backend
 *   confirm         legal acceptance + payment context + the price the customer saw → the pickup time is HELD,
 *                   the promotion use is held → READY, with the payment preparation
 *   cancel          the customer leaves; hold and promotion use are given back
 *   beginPayment    for Module 30: READY → PAYMENT_PENDING, atomically, for exactly the price that was confirmed
 *
 * Rules that hold everywhere:
 *  - a checkout belongs to one customer and is found only for that customer (anybody else gets "not found");
 *  - the restaurant, the currency and every amount come from the cart, the menu and the configured rules — a request
 *    carries no amount, and an amount that is sent is refused before it reaches this class;
 *  - a cart has at most ONE live checkout (DRAFT / READY / PAYMENT_PENDING): a second device gets the same one, a
 *    changed situation replaces it;
 *  - a checkout is never repaired: when the cart, the pickup, a price, the promotion or a tax / fee rule changes it
 *    becomes STALE and a new one is created, so the customer always sees the new total before paying;
 *  - it is NOT an order, and it charges nothing.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CheckoutValidationService $validation,
        private readonly CheckoutPricingService $pricing,
        private readonly PromotionEligibilityService $promotions,
        private readonly PickupSlotHoldService $holds,
        private readonly LegalDocuments $legal,
        private readonly PaymentOptions $payments,
        private readonly CartStore $carts,
        private readonly CartPresenter $cartPresenter,
    ) {}

    /**
     * The customer's checkout for their cart: the live one when it is still exactly right, otherwise a new one.
     *
     * @param  array{promotion_code?: string|null, cart_version?: int|null}  $input
     *
     * @throws ApiException 404 cart_not_found; 409 cart_empty / cart_invalid / item_unavailable / restaurant_unavailable /
     *                      restaurant_not_accepting / pickup_required / pickup_invalid / pickup_slot_full /
     *                      pickup_selection_expired / cart_version_conflict / payment_in_progress; 410 cart_expired;
     *                      422 promotion_*
     */
    public function open(Customer $customer, array $input = [], ?CarbonInterface $at = null): CheckoutView
    {
        $at = CarbonImmutable::instance($at ?? now());

        return DB::transaction(function () use ($customer, $input, $at): CheckoutView {
            // Two devices of one customer are put in a line here: the second one finds what the first one created.
            $this->carts->lock(CartOwner::customer($customer));

            $context = $this->validation->validate($customer, $at, acceptPriceChanges: true);
            if (! $context->ok()) {
                throw $this->blocked($context);
            }
            $cart = $context->cart;
            if (isset($input['cart_version']) && (int) $input['cart_version'] !== (int) $cart->version && $context->changes === []) {
                throw ApiException::conflict('cart_version_conflict', 'Your cart changed somewhere else. Reload it and try again.', ['current_version' => (int) $cart->version]);
            }

            $live = CheckoutSession::query()->where('cart_id', $cart->getKey())->whereIn('status', [Status::Draft->value, Status::Ready->value, Status::PaymentPending->value])->lockForUpdate()->first();
            if ($live?->status === Status::PaymentPending) {
                throw ApiException::conflict(Code::PaymentInProgress->errorCode(), 'A payment for this cart is in progress.', ['checkout_id' => (string) $live->public_id]);
            }

            $code = isset($input['promotion_code']) ? trim((string) $input['promotion_code']) : '';
            // The code the customer entered stays theirs across checkouts of the same cart: it is carried from the live
            // checkout, or from the last one when that ran out or went stale (not when the customer cancelled it) —
            // and judged again. When it no longer applies the customer is told why the total is higher.
            $previous = $live ?? CheckoutSession::query()->where('cart_id', $cart->getKey())->orderByDesc('id')->first();
            $carried = $previous?->promotion_id !== null && in_array($previous->status, [Status::Draft, Status::Ready, Status::Stale, Status::Expired], true);
            $promotion = $code !== '' ? $this->promotions->find($code) : ($carried ? Promotion::query()->find($previous->promotion_id) : null);
            $pricing = $this->pricing->price($context->inspection, $context->pickup, $customer, $promotion, $at, $live?->getKey());
            $changes = $context->changes;
            if ($code !== '' && ! $pricing->promotion->applied()) {
                // A code the customer typed that does not apply is an answer, not a checkout.
                event(new CheckoutEvent(CheckoutEvent::PROMOTION_REFUSED, $live, ['reason' => $pricing->promotion->rejection?->value ?? 'PROMOTION_NOT_FOUND']));
                throw $this->promotionRefused($promotion === null ? PromotionDecision::rejected(null, PromotionRejection::NotFound) : $pricing->promotion);
            }
            if ($code === '' && $promotion !== null && ! $pricing->promotion->applied()) {
                // The promotion of the earlier checkout no longer applies: priced without it, and the customer is told.
                $changes[] = ['code' => Code::PromotionChanged->value, 'promotion_code' => (string) $promotion->code, 'reason' => $pricing->promotion->rejection?->customerCode()];
                $pricing = $this->pricing->price($context->inspection, $context->pickup, $customer, null, $at, $live?->getKey());
            }

            if ($live !== null && $this->matches($live, $context, $pricing, $at)) {
                event(new CheckoutEvent(CheckoutEvent::REUSED, $live));

                return new CheckoutView($live, [], [], false);
            }
            if ($live !== null) {
                if ((int) $live->total_minor !== $pricing->totalMinor()) {
                    $changes[] = ['code' => Code::PricingChanged->value, 'previous_total' => $live->total()->toArray(), 'total' => $pricing->total()->toArray()];
                }
                $expired = $at->greaterThanOrEqualTo($live->expires_at);
                $this->close($live, $expired ? Status::Expired : Status::Cancelled, $expired ? Code::CheckoutExpired->value : 'REPLACED', $at);
            }

            $session = $this->fill(new CheckoutSession, $customer, $context, $pricing, $changes, $at);
            $session->forceFill(['status' => Status::Draft->value, 'expires_at' => $at->addMinutes(max(1, (int) config('checkout.ttl.draft_minutes'))), 'version' => 1])->save();
            event(new CheckoutEvent(CheckoutEvent::CREATED, $session, ['promotion' => $pricing->promotion->applied()]));
            if ($pricing->promotion->applied()) {
                event(new CheckoutEvent(CheckoutEvent::PROMOTION_APPLIED, $session));
            }

            return new CheckoutView($session->refresh(), [], $changes, true);
        });
    }

    /**
     * The customer's own checkout, judged now.
     *
     * @throws ApiException 404 checkout_not_found
     */
    public function show(Customer $customer, string $checkoutId, ?CarbonInterface $at = null): CheckoutView
    {
        $at = CarbonImmutable::instance($at ?? now());

        return DB::transaction(fn (): CheckoutView => $this->judge($this->own($customer, $checkoutId, lock: true), $customer, $at)[0]);
    }

    /**
     * Applies one promotion code (replacing an earlier one) or, with null, removes it. The checkout is priced again
     * by the backend; a confirmed checkout goes back to DRAFT because its total changed.
     *
     * @throws ApiException 404 checkout_not_found; 409 checkout_stale / payment_in_progress; 410 checkout_expired; 422 promotion_*
     */
    public function setPromotion(Customer $customer, string $checkoutId, ?string $code, ?CarbonInterface $at = null): CheckoutView
    {
        $at = CarbonImmutable::instance($at ?? now());

        return $this->unlessRefused(DB::transaction(function () use ($customer, $checkoutId, $code, $at): CheckoutView|ApiException {
            $session = $this->own($customer, $checkoutId, lock: true);
            [$view, $context] = $this->judge($session, $customer, $at);
            if (($refusal = $this->refusal($view)) !== null) {
                return $refusal;
            }

            $promotion = $code === null ? null : $this->promotions->find($code);
            $pricing = $this->pricing->price($context->inspection, $context->pickup, $customer, $promotion, $at, $session->getKey());
            if ($code !== null && ! $pricing->promotion->applied()) {
                $decision = $promotion === null ? PromotionDecision::rejected(null, PromotionRejection::NotFound) : $pricing->promotion;
                event(new CheckoutEvent(CheckoutEvent::PROMOTION_REFUSED, $session, ['reason' => $decision->rejection->value]));
                throw $this->promotionRefused($decision);
            }

            // The price changes, so whatever was confirmed for the old price is undone.
            $this->holds->release($session);
            $this->releasePromotionUse($session);
            $this->fill($session, $customer, $context, $pricing, [], $at);
            $session->forceFill([
                'status' => Status::Draft->value,
                'confirmed_at' => null,
                'expires_at' => $at->addMinutes(max(1, (int) config('checkout.ttl.draft_minutes'))),
                'version' => (int) $session->version + 1,
            ])->save();
            event(new CheckoutEvent($code === null ? CheckoutEvent::PROMOTION_REMOVED : CheckoutEvent::PROMOTION_APPLIED, $session));

            return new CheckoutView($session->refresh());
        }));
    }

    /**
     * Confirms the checkout: the customer accepted the current legal documents, named how they intend to pay, and
     * saw exactly this price. The pickup time is held and — for a limited promotion — one use of it.
     *
     * @param  array{pricing_fingerprint: string, accepted_documents?: list<array{type: string, version: string}>, payment_method?: array{type?: string|null, saved_method_id?: string|null}|null, order_note?: string|null}  $input
     *
     * @throws ApiException 404 checkout_not_found; 409 checkout_stale / pickup_slot_unavailable / promotion_limit_reached /
     *                      payment_in_progress; 410 checkout_expired; 422 legal_acceptance_required /
     *                      payment_method_required / payment_method_unavailable
     */
    public function confirm(Customer $customer, string $checkoutId, array $input, ?string $requestId = null, ?CarbonInterface $at = null): CheckoutView
    {
        $at = CarbonImmutable::instance($at ?? now());

        return $this->unlessRefused(DB::transaction(function () use ($customer, $checkoutId, $input, $requestId, $at): CheckoutView|ApiException {
            $session = $this->own($customer, $checkoutId, lock: true);
            [$view, $context] = $this->judge($session, $customer, $at);
            if (($refusal = $this->refusal($view)) !== null) {
                return $refusal;
            }

            // The customer must have seen THIS price. A page that still shows another one is told to look again.
            if (! hash_equals((string) $session->pricing_fingerprint, (string) ($input['pricing_fingerprint'] ?? ''))) {
                throw ApiException::conflict(Code::CheckoutStale->errorCode(), 'The price of this checkout is not the one you were shown. Please review it again.', ['reason' => Code::PricingChanged->value, 'pricing_fingerprint' => (string) $session->pricing_fingerprint]);
            }

            $location = $context->cart->location;
            $required = $this->legal->requiredFor($location->market);
            $missing = $this->legal->missing($required, (array) ($input['accepted_documents'] ?? []));
            if ($missing !== []) {
                throw new ApiException(422, Code::LegalAcceptanceRequired->errorCode(), 'Please accept the current terms before continuing.', ['missing' => $missing]);
            }
            [$methodType, $savedMethod] = $this->paymentContext($customer, $session, $location->market, (array) ($input['payment_method'] ?? []));
            $note = PlainText::clean($input['order_note'] ?? null, 'order_note', true);

            // An ASAP pickup follows the clock: what is held is the time as estimated now.
            $pickup = $context->pickup;
            $selection = $pickup->selection;
            [$slotStart, $slotEnd] = PickupSlotHoldService::slotOf($selection->mode, $pickup->pickupAt, $selection->slot_ends_at, (int) ($selection->slot_interval_minutes ?? $location->pickupSettings?->slot_interval_minutes ?? 15), (string) $location->timezone);
            // A confirmation that is repeated does not prolong anything: the hold ends when it was first said to end.
            $expiresAt = $session->status === Status::Ready ? CarbonImmutable::instance($session->expires_at) : $at->addMinutes(max(1, (int) config('checkout.ttl.ready_minutes')));
            $session->forceFill(['pickup_at' => $pickup->pickupAt, 'pickup_slot_starts_at' => $slotStart, 'pickup_slot_ends_at' => $slotEnd]);

            if ($this->holds->hold($session, $location, $expiresAt) === null) {
                event(new CheckoutEvent(CheckoutEvent::HOLD_FAILED, $session, ['reason' => Code::PickupSlotFull->value]));
                throw ApiException::conflict(Code::PickupSlotUnavailable->errorCode(), 'This pickup time has just been taken. Please choose another time.', ['issues' => [CheckoutContext::issue(Code::PickupSlotFull, 'This pickup time has just been taken. Please choose another time.')]]);
            }
            $this->holdPromotionUse($session, $customer, $context, $expiresAt, $at);

            foreach ($required as $document) {
                CheckoutLegalAcceptance::query()->where('checkout_session_id', $session->getKey())->where('document_type', $document['type'])->delete();
                (new CheckoutLegalAcceptance)->forceFill([
                    'checkout_session_id' => $session->getKey(),
                    'customer_id' => $customer->getKey(),
                    'document_type' => $document['type'],
                    'document_version' => $document['version'],
                    'document_effective_date' => $document['effective_date'],
                    'document_status' => $document['status'],
                    'accepted_at' => $at,
                    'request_id' => $requestId,
                ])->save();
            }

            $first = $session->status !== Status::Ready;
            $session->forceFill([
                'status' => Status::Ready->value,
                'status_reason' => null,
                'confirmed_at' => $at,
                'expires_at' => $expiresAt,
                'order_note' => $note,
                'payment_method_type' => $methodType,
                'customer_payment_method_id' => $savedMethod?->getKey(),
                'version' => (int) $session->version + ($first ? 1 : 0),
            ])->save();
            if ($first) {
                event(new CheckoutEvent(CheckoutEvent::CONFIRMED, $session, ['payment_required' => (bool) $session->payment_required]));
            }

            return new CheckoutView($session->refresh());
        }));
    }

    /**
     * The customer leaves this checkout. The held pickup time and promotion use are given back. Safe to repeat.
     *
     * @throws ApiException 404 checkout_not_found; 409 payment_in_progress
     */
    public function cancel(Customer $customer, string $checkoutId, ?CarbonInterface $at = null): CheckoutView
    {
        $at = CarbonImmutable::instance($at ?? now());

        return DB::transaction(function () use ($customer, $checkoutId, $at): CheckoutView {
            $session = $this->own($customer, $checkoutId, lock: true);
            if ($session->status === Status::PaymentPending) {
                throw ApiException::conflict(Code::PaymentInProgress->errorCode(), 'A payment for this checkout is in progress.');
            }
            if ($session->status->isOpen()) {
                $this->close($session, Status::Cancelled, 'CUSTOMER', $at);
                event(new CheckoutEvent(CheckoutEvent::CANCELLED, $session));
            }

            return new CheckoutView($session->refresh());
        });
    }

    /**
     * For Module 30: a payment is about to be created for this checkout. READY → PAYMENT_PENDING in one step, only
     * while the checkout is still exactly what was confirmed and only for the price the caller names. From then on
     * the cart and the pickup selection cannot be changed until the payment ends.
     *
     * @throws ApiException 409 checkout_stale / payment_in_progress / checkout_not_ready; 410 checkout_expired
     */
    public function beginPayment(Customer $customer, string $checkoutId, string $pricingFingerprint, ?CarbonInterface $at = null): PaymentPreparation
    {
        $at = CarbonImmutable::instance($at ?? now());

        return $this->unlessRefused(DB::transaction(function () use ($customer, $checkoutId, $pricingFingerprint, $at): PaymentPreparation|ApiException {
            $session = $this->own($customer, $checkoutId, lock: true);
            if ($session->status === Status::PaymentPending) {
                throw ApiException::conflict(Code::PaymentInProgress->errorCode(), 'A payment for this checkout is already in progress.');
            }
            [$view] = $this->judge($session, $customer, $at);
            if (($refusal = $this->refusal($view)) !== null) {
                return $refusal;
            }
            if ($session->status !== Status::Ready) {
                throw ApiException::conflict('checkout_not_ready', 'This checkout has not been confirmed.');
            }
            if (! hash_equals((string) $session->pricing_fingerprint, $pricingFingerprint)) {
                throw ApiException::conflict(Code::CheckoutStale->errorCode(), 'The payment does not match the confirmed price.', ['reason' => Code::PricingChanged->value]);
            }
            $session->forceFill(['status' => Status::PaymentPending->value, 'payment_started_at' => $at])->save();
            event(new CheckoutEvent(CheckoutEvent::PAYMENT_STARTED, $session));

            return PaymentPreparation::for($session->refresh());
        }));
    }

    /**
     * The cart or its pickup selection changed. Every open checkout of that cart that no longer matches it — another
     * cart version, another pickup selection, or no usable cart at all — is stale from this moment and gives its held
     * pickup time back at once, not only when somebody next looks at it. A checkout that was created for the cart as
     * it is now is left alone.
     */
    public function invalidateForCart(string $cartPublicId, ?CarbonInterface $at = null): int
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cart = Cart::query()->with('pickupSelection')->where('public_id', $cartPublicId)->first();
        if ($cart === null) {
            return 0;
        }
        $closed = 0;
        foreach (CheckoutSession::query()->where('cart_id', $cart->getKey())->whereIn('status', [Status::Draft->value, Status::Ready->value])->get() as $session) {
            $selection = $cart->pickupSelection;
            $reason = match (true) {
                $cart->status !== CartStatus::Active || (int) $cart->version !== (int) $session->cart_version => Code::CartChanged,
                $selection === null || (int) $selection->getKey() !== (int) $session->pickup_selection_id || (int) $selection->version !== (int) $session->pickup_selection_version => Code::PickupChanged,
                default => null,
            };
            if ($reason === null) {
                continue;
            }
            $this->close($session, Status::Stale, $reason->value, $at);
            event(new CheckoutEvent(CheckoutEvent::STALE, $session, ['reason' => $reason->value]));
            $closed++;
        }

        return $closed;
    }

    /**
     * Housekeeping: run-out checkouts become EXPIRED and give back what they held; run-out holds and promotion uses
     * are closed; closed checkouts are deleted after the retention period. A checkout that led to a payment or an
     * order (PAYMENT_PENDING, CONVERTED) is never touched.
     *
     * @return array{expired: int, holds_expired: int, promotion_uses_released: int, deleted: int}
     */
    public function prune(?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $expired = 0;
        CheckoutSession::query()->whereIn('status', [Status::Draft->value, Status::Ready->value])->where('expires_at', '<=', $at)->orderBy('id')
            ->chunkById(200, function ($sessions) use (&$expired, $at): void {
                foreach ($sessions as $session) {
                    $this->close($session, Status::Expired, Code::CheckoutExpired->value, $at);
                    event(new CheckoutEvent(CheckoutEvent::EXPIRED, $session));
                    $expired++;
                }
            });
        $holds = $this->holds->expireRunOut($at);
        $uses = PromotionReservation::query()->where('status', PromotionReservation::STATUS_ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<=', $at)
            ->update(['status' => PromotionReservation::STATUS_RELEASED, 'updated_at' => $at]);
        $deleted = CheckoutSession::query()
            ->whereIn('status', [Status::Expired->value, Status::Stale->value, Status::Cancelled->value])
            ->where('closed_at', '<', $at->subDays(max(1, (int) config('checkout.ttl.closed_retention_days'))))
            ->delete();

        return ['expired' => $expired, 'holds_expired' => $holds, 'promotion_uses_released' => (int) $uses, 'deleted' => (int) $deleted];
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * Judges an open checkout against the present: expired, stale, or still exactly right. Closed checkouts are
     * returned as they are.
     *
     * @return array{0: CheckoutView, 1: CheckoutContext|null}
     */
    private function judge(CheckoutSession $session, Customer $customer, CarbonImmutable $at): array
    {
        if (! $session->status->isOpen()) {
            return [new CheckoutView($session, $this->closedIssues($session)), null];
        }
        if ($at->greaterThanOrEqualTo($session->expires_at)) {
            $this->close($session, Status::Expired, Code::CheckoutExpired->value, $at);
            event(new CheckoutEvent(CheckoutEvent::EXPIRED, $session));

            return [new CheckoutView($session, $this->closedIssues($session)), null];
        }

        $context = $this->validation->validate($customer, $at);
        $issues = $context->issues;
        if ($issues === [] && ($context->cart === null || (int) $context->cart->getKey() !== (int) $session->cart_id || (int) $context->cart->version !== (int) $session->cart_version)) {
            $issues[] = CheckoutContext::issue(Code::CartChanged, 'Your cart has changed since this checkout was prepared.');
        }
        if ($issues === [] && ((int) $context->pickup->selection->getKey() !== (int) $session->pickup_selection_id || (int) $context->pickup->selection->version !== (int) $session->pickup_selection_version)) {
            $issues[] = CheckoutContext::issue(Code::PickupChanged, 'Your pickup time has changed since this checkout was prepared.');
        }
        if ($issues === []) {
            $promotion = $session->promotion_id === null ? null : Promotion::query()->find($session->promotion_id);
            $pricing = $this->pricing->price($context->inspection, $context->pickup, $customer, $promotion, $at, $session->getKey());
            if ($session->promotion_code !== null && ! $pricing->promotion->applied()) {
                $rejection = $pricing->promotion->rejection;
                $issues[] = CheckoutContext::issue($rejection === PromotionRejection::Expired ? Code::PromotionExpired : Code::PromotionChanged, $rejection?->customerMessage() ?? 'The promotion of this checkout is no longer available.', ['promotion_code' => (string) $session->promotion_code]);
            } elseif (! hash_equals((string) $session->pricing_fingerprint, $pricing->fingerprint)) {
                $code = $this->whatChanged((array) ($session->snapshot['basis'] ?? []), $pricing->basis);
                $issues[] = CheckoutContext::issue($code, 'The price of this checkout has changed. Please review the new total.', ['previous_total' => $session->total()->toArray(), 'total' => $pricing->total()->toArray()]);
            }
        }
        if ($issues === [] && $session->status === Status::Ready) {
            // What was accepted must still be what is in force: a document that got a new version is accepted again.
            $accepted = $session->legalAcceptances()->get()->map(fn (CheckoutLegalAcceptance $a): array => ['type' => $a->document_type->value, 'version' => (string) $a->document_version])->all();
            if ($this->legal->missing($this->legal->requiredFor($context->cart->location->market), $accepted) !== []) {
                $issues[] = CheckoutContext::issue(Code::LegalAcceptanceRequired, 'Our terms have been updated. Please review and accept them again.');
            }
        }

        if ($issues !== []) {
            $this->close($session, Status::Stale, (string) $issues[0]['code'], $at);
            event(new CheckoutEvent(CheckoutEvent::STALE, $session, ['reason' => (string) $issues[0]['code']]));

            return [new CheckoutView($session, $issues), $context];
        }

        return [new CheckoutView($session), $context];
    }

    /**
     * Is the live checkout still exactly what would be created now?
     */
    private function matches(CheckoutSession $live, CheckoutContext $context, CheckoutPricing $pricing, CarbonImmutable $at): bool
    {
        return $live->status->isOpen()
            && $at->lessThan($live->expires_at)
            && $context->changes === []
            && (int) $live->cart_version === (int) $context->cart->version
            && (int) $live->pickup_selection_id === (int) $context->pickup->selection->getKey()
            && (int) $live->pickup_selection_version === (int) $context->pickup->selection->version
            && hash_equals((string) $live->pricing_fingerprint, $pricing->fingerprint);
    }

    /**
     * Writes what a checkout is built from onto the session (not its status, expiry or version).
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function fill(CheckoutSession $session, Customer $customer, CheckoutContext $context, CheckoutPricing $pricing, array $changes, CarbonImmutable $at): CheckoutSession
    {
        $cart = $context->cart;
        $location = $cart->location;
        $pickup = $context->pickup;
        $selection = $pickup->selection;
        $decision = $pricing->promotion;
        [$slotStart, $slotEnd] = PickupSlotHoldService::slotOf($selection->mode, $pickup->pickupAt, $selection->slot_ends_at, (int) ($selection->slot_interval_minutes ?? $location->pickupSettings?->slot_interval_minutes ?? 15), (string) $location->timezone);

        return $session->forceFill([
            'customer_id' => $customer->getKey(),
            'cart_id' => $cart->getKey(),
            'cart_version' => (int) $cart->version,
            'pickup_selection_id' => $selection->getKey(),
            'pickup_selection_version' => (int) $selection->version,
            'pickup_mode' => $selection->mode->value,
            'pickup_method' => $selection->pickup_method->value,
            'pickup_at' => $pickup->pickupAt,
            'pickup_slot_starts_at' => $slotStart,
            'pickup_slot_ends_at' => $slotEnd,
            'restaurant_timezone' => (string) $location->timezone,
            'location_id' => $location->getKey(),
            'market_id' => $location->market_id,
            'currency' => $pricing->currency,
            'subtotal_minor' => $pricing->subtotalMinor,
            'discount_minor' => $pricing->discountMinor(),
            'tax_minor' => $pricing->taxMinor(),
            'tax_included_minor' => $pricing->taxIncludedMinor(),
            'fee_minor' => $pricing->feeMinor(),
            'total_minor' => $pricing->totalMinor(),
            'payment_required' => $pricing->paymentRequired(),
            'pricing_fingerprint' => $pricing->fingerprint,
            'promotion_id' => $decision->applied() ? $decision->promotion->getKey() : null,
            'promotion_code' => $decision->applied() ? (string) $decision->promotion->code : null,
            'snapshot' => [
                'restaurant' => ['id' => (string) $location->public_id, 'slug' => (string) $location->slug, 'name' => (string) $location->name, 'timezone' => (string) $location->timezone],
                'items' => array_map(fn (CartLineInspection $line): array => array_intersect_key($this->cartPresenter->line($line), array_flip(['id', 'item', 'name', 'quantity', 'special_instructions', 'variants', 'modifiers', 'unit_price_minor', 'line_subtotal_minor', 'currency'])), $context->inspection->lines),
                'item_count' => $context->inspection->itemCount,
                'pickup' => [
                    'estimated_prep_minutes' => (int) $selection->estimated_prep_minutes,
                    'traveler_arrival_at' => $selection->traveler_arrival_at?->utc()->toIso8601String(),
                ],
                'promotion' => $decision->applied() ? ['code' => (string) $decision->promotion->code, 'name' => (string) $decision->promotion->name, 'type' => $decision->promotion->type->value] : null,
                'taxes' => $pricing->taxes->lines,
                'fees' => $pricing->fees->lines,
                'configuration' => ['tax' => $pricing->taxes->configuration, 'fees' => $pricing->fees->configuration],
                'changes' => $changes,
                'basis' => $pricing->basis,
                'priced_at' => $at->utc()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Ends a checkout and gives back what it held.
     */
    private function close(CheckoutSession $session, Status $status, string $reason, CarbonImmutable $at): void
    {
        $this->holds->release($session);
        $this->releasePromotionUse($session);
        $session->forceFill(['status' => $status->value, 'status_reason' => mb_substr($reason, 0, 40), 'closed_at' => $at])->save();
    }

    private function releasePromotionUse(CheckoutSession $session): void
    {
        PromotionReservation::query()->where('checkout_session_id', $session->getKey())->where('status', PromotionReservation::STATUS_ACTIVE)
            ->update(['status' => PromotionReservation::STATUS_RELEASED, 'updated_at' => now()]);
    }

    /**
     * A limited promotion is checked once more with the promotion row locked — of two customers confirming at the
     * same moment for the last use, exactly one keeps it — and one use is held until the checkout ends.
     */
    private function holdPromotionUse(CheckoutSession $session, Customer $customer, CheckoutContext $context, CarbonImmutable $expiresAt, CarbonImmutable $at): void
    {
        if ($session->promotion_id === null) {
            return;
        }
        $promotion = Promotion::query()->whereKey($session->promotion_id)->lockForUpdate()->first();
        $decision = $this->promotions->evaluate($promotion, $customer, $context->cart->location, (string) $session->currency, (int) $session->subtotal_minor, $at, $session->getKey());
        if (! $decision->applied()) {
            event(new CheckoutEvent(CheckoutEvent::PROMOTION_REFUSED, $session, ['reason' => $decision->rejection->value]));
            throw ApiException::conflict($decision->rejection->customerCode(), $decision->rejection->customerMessage(), ['promotion_code' => (string) $session->promotion_code, 'reason' => Code::PromotionChanged->value]);
        }
        $use = PromotionReservation::query()->where('checkout_session_id', $session->getKey())->first() ?? new PromotionReservation;
        $use->forceFill([
            'promotion_id' => $promotion->getKey(),
            'checkout_session_id' => $session->getKey(),
            'customer_id' => $customer->getKey(),
            'status' => PromotionReservation::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
        ])->save();
    }

    /**
     * @param  array{type?: string|null, saved_method_id?: string|null}  $input
     * @return array{0: string|null, 1: CustomerPaymentMethod|null}
     */
    private function paymentContext(Customer $customer, CheckoutSession $session, Market $market, array $input): array
    {
        if (! $session->payment_required) {
            // Nothing to pay: no method is asked for and none is kept.
            return [null, null];
        }
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if ($type === '') {
            throw new ApiException(422, Code::PaymentMethodRequired->errorCode(), 'Choose how you would like to pay.');
        }
        if (! $this->payments->offers($market, $type)) {
            throw new ApiException(422, Code::PaymentMethodUnavailable->errorCode(), 'This way of paying is not available.', ['type' => $type]);
        }
        $saved = null;
        if (! empty($input['saved_method_id'])) {
            $saved = CustomerPaymentMethod::query()
                ->where('customer_id', $customer->getKey())
                ->where('public_id', (string) $input['saved_method_id'])
                ->where('status', PaymentMethodStatus::Active->value)
                ->first();
            if ($saved === null || $saved->type->value !== $type || $saved->effectiveStatus() !== PaymentMethodStatus::Active) {
                throw new ApiException(422, Code::PaymentMethodUnavailable->errorCode(), 'This saved payment method cannot be used.');
            }
        }

        return [$type, $saved];
    }

    private function own(Customer $customer, string $checkoutId, bool $lock = false): CheckoutSession
    {
        $query = CheckoutSession::query()->where('public_id', $checkoutId)->where('customer_id', $customer->getKey());

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw ApiException::notFound('checkout_not_found', 'This checkout does not exist.');
    }

    /**
     * Why a checkout that is no longer open cannot be used: 409 checkout_stale / payment_in_progress, 410
     * checkout_expired — null while it is open.
     *
     * It is RETURNED, not thrown, inside the transaction: the judgement that just closed the checkout (STALE or
     * EXPIRED, its pickup hold and promotion use given back) has to be committed, and an exception would undo it.
     */
    private function refusal(CheckoutView $view): ?ApiException
    {
        $session = $view->session;
        if ($session->status->isOpen()) {
            return null;
        }

        return match ($session->status) {
            Status::Expired => new ApiException(410, Code::CheckoutExpired->errorCode(), 'This checkout has expired. Please start it again.', ['issues' => $view->issues]),
            Status::PaymentPending, Status::Converted => ApiException::conflict(Code::PaymentInProgress->errorCode(), 'A payment for this checkout has already been started.'),
            default => ApiException::conflict(Code::CheckoutStale->errorCode(), 'This checkout is out of date. Please review it again.', ['reason' => (string) $session->status_reason, 'issues' => $view->issues]),
        };
    }

    /**
     * @template T of CheckoutView|PaymentPreparation
     *
     * @param  T|ApiException  $outcome  what a transaction returned
     * @return T
     *
     * @throws ApiException
     */
    private function unlessRefused(CheckoutView|PaymentPreparation|ApiException $outcome): CheckoutView|PaymentPreparation
    {
        return $outcome instanceof ApiException ? throw $outcome : $outcome;
    }

    /**
     * @return list<array{code: string, message: string, recover: string, details: array<string, mixed>}>
     */
    private function closedIssues(CheckoutSession $session): array
    {
        return match ($session->status) {
            Status::Expired => [CheckoutContext::issue(Code::CheckoutExpired, 'This checkout has expired. Please start it again.')],
            Status::Stale => [CheckoutContext::issue(Code::tryFrom((string) $session->status_reason) ?? Code::CheckoutStale, 'This checkout is out of date. Please review it again.')],
            default => [],
        };
    }

    /**
     * Names what moved the price, from the two bases a fingerprint is made of.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $now
     */
    private function whatChanged(array $before, array $now): Code
    {
        return match (true) {
            ($before['lines'] ?? null) != ($now['lines'] ?? null) => Code::PriceChanged,
            ($before['promotion'] ?? null) != ($now['promotion'] ?? null) => Code::PromotionChanged,
            ($before['taxes'] ?? null) != ($now['taxes'] ?? null) => Code::TaxConfigurationChanged,
            ($before['fees'] ?? null) != ($now['fees'] ?? null) => Code::FeeConfigurationChanged,
            default => Code::PricingChanged,
        };
    }

    private function blocked(CheckoutContext $context): ApiException
    {
        $first = Code::from($context->issues[0]['code']);
        event(new CheckoutEvent(CheckoutEvent::BLOCKED, null, ['reason' => $first->value]));

        return new ApiException(match ($first) {
            Code::CartMissing => 404,
            Code::CartExpired => 410,
            default => 409,
        }, $first->errorCode(), (string) $context->issues[0]['message'], ['issues' => $context->issues]);
    }

    private function promotionRefused(PromotionDecision $decision): ApiException
    {
        return new ApiException(422, $decision->rejection->customerCode(), $decision->rejection->customerMessage(), $decision->details);
    }
}
