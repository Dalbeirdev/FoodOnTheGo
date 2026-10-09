<?php

namespace Tests\Feature\Checkout;

use App\Exceptions\ApiException;
use App\Models\CheckoutLegalAcceptance;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\PickupSlotReservation;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\PaymentPreparation;
use App\Services\Customer\PaymentMethodReferenceService;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * What a confirmed checkout prepares for the payment module (Module 29): the legal acceptance, the way the customer
 * intends to pay, and a payment PREPARATION bound to one price. Nothing is charged and no provider is called.
 */
class CheckoutPaymentPreparationTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    private Customer $customer;

    private array $asha;

    private array $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        [$this->customer, $this->asha] = $this->customerReadyForCheckout();   // ₹500
        $this->checkout = $this->openCheckout($this->asha)->assertCreated()->json('checkout');
    }

    private function saved(Customer $customer, array $attributes = []): CustomerPaymentMethod
    {
        return app(PaymentMethodReferenceService::class)->attach($customer, ['type' => 'CARD', 'provider' => 'development', 'provider_customer_reference' => 'dev_cust_1', 'provider_payment_method_reference' => 'dev_pm_'.bin2hex(random_bytes(4)), 'brand' => 'Visa', 'display_label' => 'Visa •••• 4242', 'last4' => '4242', 'expiry_month' => 12, 'expiry_year' => 2028, 'is_default' => true, ...$attributes]);
    }

    /* ------------------------------------------------------------------ legal acceptance */

    public function test_the_checkout_names_the_documents_to_accept_with_their_version_and_honest_status(): void
    {
        $legal = $this->checkout['legal'];

        $this->assertSame([
            ['type' => 'TERMS', 'version' => 'draft-2026-09', 'effective_date' => null, 'status' => 'DRAFT_PENDING_APPROVAL', 'slug' => 'terms'],
            ['type' => 'PRIVACY', 'version' => 'draft-2026-09', 'effective_date' => null, 'status' => 'DRAFT_PENDING_APPROVAL', 'slug' => 'privacy'],
            ['type' => 'REFUND', 'version' => 'draft-2026-09', 'effective_date' => null, 'status' => 'DRAFT_PENDING_APPROVAL', 'slug' => 'refund-policy'],
        ], $legal['required']);
        $this->assertSame([], $legal['accepted']);
        // No text has final approval: said as it is.
        $this->assertFalse($legal['final_approval']);

        // The flag is derived from the market's configuration, not written into the code.
        $this->marketConfiguration(['legal' => ['documents' => ['terms' => 'APPROVED', 'privacy' => 'APPROVED', 'refund' => 'APPROVED']]]);
        $this->assertTrue($this->showCheckout($this->asha, $this->checkout['id'])->json('checkout.legal.final_approval'));
        $this->marketConfiguration(['legal' => ['documents' => ['terms' => 'APPROVED', 'privacy' => 'APPROVED', 'refund' => 'DRAFT_PENDING_APPROVAL']]]);
        $this->assertFalse($this->showCheckout($this->asha, $this->checkout['id'])->json('checkout.legal.final_approval'));
    }

    public function test_a_checkout_is_not_ready_without_the_acceptance_of_every_required_document(): void
    {
        $none = $this->confirmCheckout($this->asha, $this->checkout, ['accepted_documents' => []])->assertUnprocessable();
        $none->assertJsonPath('error.code', 'legal_acceptance_required');
        $this->assertSame(['TERMS', 'PRIVACY', 'REFUND'], array_column($none->json('error.details.missing'), 'type'));

        // Two of three is not enough, and an older version is not the current one.
        $this->confirmCheckout($this->asha, $this->checkout, ['accepted_documents' => [['type' => 'TERMS', 'version' => 'draft-2026-09'], ['type' => 'PRIVACY', 'version' => 'draft-2026-09']]])
            ->assertUnprocessable()->assertJsonPath('error.details.missing', [['type' => 'REFUND', 'version' => 'draft-2026-09']]);
        $this->confirmCheckout($this->asha, $this->checkout, ['accepted_documents' => [['type' => 'TERMS', 'version' => 'draft-2025-01'], ['type' => 'PRIVACY', 'version' => 'draft-2026-09'], ['type' => 'REFUND', 'version' => 'draft-2026-09']]])
            ->assertUnprocessable()->assertJsonPath('error.details.missing', [['type' => 'TERMS', 'version' => 'draft-2026-09']]);

        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);
        $this->assertSame(0, CheckoutLegalAcceptance::query()->count());
        $this->assertSame(0, PickupSlotReservation::query()->count(), 'nothing is held for a checkout that is not confirmed');
    }

    public function test_the_acceptance_is_recorded_per_document_with_its_version_and_the_server_time(): void
    {
        $ready = $this->confirmCheckout($this->asha, $this->checkout)->assertOk()->json('checkout');

        $rows = CheckoutLegalAcceptance::query()->orderBy('id')->get();
        $this->assertSame(['TERMS', 'PRIVACY', 'REFUND'], $rows->map(fn ($r) => $r->document_type->value)->all());
        foreach ($rows as $row) {
            $this->assertSame(['draft-2026-09', 'DRAFT_PENDING_APPROVAL', (int) $this->customer->getKey(), (int) $this->sessionModel($ready['id'])->getKey()], [$row->document_version, $row->document_status, (int) $row->customer_id, (int) $row->checkout_session_id]);
            $this->assertTrue($row->accepted_at->equalTo(now()), 'the server clock, not a time the client names');
            $this->assertNotEmpty($row->request_id);
        }
        // Not "accepted = true", and not surveillance either: what was accepted and when — no address, no device.
        $columns = DB::getSchemaBuilder()->getColumnListing('checkout_legal_acceptances');
        $this->assertSame([], array_intersect($columns, ['accepted', 'accepted_terms', 'ip', 'ip_address', 'user_agent', 'device', 'location', 'marketing_consent']));
        $this->assertEqualsCanonicalizing(['TERMS', 'PRIVACY', 'REFUND'], array_column($ready['legal']['accepted'], 'type'));
        $this->assertSame(['type', 'version', 'status', 'accepted_at'], array_keys($ready['legal']['accepted'][0]));

        // Confirming again records nothing twice.
        $this->confirmCheckout($this->asha, $ready)->assertOk();
        $this->assertSame(3, CheckoutLegalAcceptance::query()->count());
    }

    public function test_a_document_that_gets_a_new_version_has_to_be_accepted_again(): void
    {
        $ready = $this->confirmCheckout($this->asha, $this->checkout)->assertOk()->json('checkout');

        config(['legal.documents.terms.version' => 'v1-2026-11', 'legal.documents.terms.effective_date' => '2026-11-01']);

        $stale = $this->showCheckout($this->asha, $ready['id'])->assertOk()->json('checkout');
        $this->assertSame(['STALE', 'LEGAL_ACCEPTANCE_REQUIRED', 'checkout'], [$stale['status'], $stale['issues'][0]['code'], $stale['issues'][0]['recover']]);
        $this->assertNull($stale['payment']);

        $next = $this->openCheckout($this->asha)->assertCreated()->json('checkout');
        $this->assertSame(['type' => 'TERMS', 'version' => 'v1-2026-11', 'effective_date' => '2026-11-01', 'status' => 'DRAFT_PENDING_APPROVAL', 'slug' => 'terms'], $next['legal']['required'][0]);
        // What the page still remembers from before is not enough.
        $this->confirmCheckout($this->asha, [...$next, 'legal' => $ready['legal']])->assertUnprocessable()->assertJsonPath('error.code', 'legal_acceptance_required');
        $this->confirmCheckout($this->asha, $next)->assertOk();
        $this->assertSame('v1-2026-11', CheckoutLegalAcceptance::query()->where('checkout_session_id', $this->sessionModel($next['id'])->getKey())->where('document_type', 'TERMS')->sole()->document_version);
    }

    /* ------------------------------------------------------------------ payment method context */

    public function test_the_checkout_offers_the_markets_method_types_and_says_no_provider_is_integrated(): void
    {
        $methods = $this->checkout['payment_methods'];

        $this->assertSame('READY', $methods['provider_status']);   // the test environment's (fake) provider
        $this->assertSame([['type' => 'UPI', 'status' => 'PLANNED'], ['type' => 'CARD', 'status' => 'PLANNED'], ['type' => 'NET_BANKING', 'status' => 'PLANNED'], ['type' => 'WALLET', 'status' => 'PLANNED']], $methods['available']);
        $this->assertSame([[], null, 'NOT_APPROVED'], [$methods['saved'], $methods['selected'], $methods['cash_at_pickup']]);
        // A draft has no payment preparation at all.
        $this->assertNull($this->checkout['payment']);

        // A method the market switches off is neither offered nor accepted.
        $this->marketConfiguration(['payment' => ['methods' => ['upi' => 'PLANNED', 'card' => 'PLANNED', 'netbanking' => 'DISABLED', 'wallet' => 'BLOCKED', 'cash_at_pickup' => 'NOT_APPROVED']]]);
        $this->assertSame(['UPI', 'CARD'], array_column($this->showCheckout($this->asha, $this->checkout['id'])->json('checkout.payment_methods.available'), 'type'));
        $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => ['type' => 'WALLET']])->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => ['type' => 'BITCOIN']])->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
    }

    public function test_a_payable_checkout_needs_a_way_to_pay(): void
    {
        $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => null])->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_required');
        $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => ['type' => '']])->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_required');
        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);

        $ready = $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => ['type' => 'card']])->assertOk()->json('checkout');
        $this->assertSame(['type' => 'CARD', 'saved_method_id' => null], $ready['payment_methods']['selected']);
        $this->assertSame(['type' => 'CARD', 'saved_method_id' => null], $ready['payment']['payment_method']);
    }

    public function test_a_saved_method_must_be_the_customers_own_usable_and_of_the_named_type(): void
    {
        $visa = $this->saved($this->customer);
        $expired = $this->saved($this->customer, ['display_label' => 'Visa •••• 1111', 'last4' => '1111', 'expiry_month' => 9, 'expiry_year' => 2026, 'is_default' => false]);
        $removed = $this->saved($this->customer, ['display_label' => 'Visa •••• 2222', 'last4' => '2222', 'is_default' => false]);
        app(PaymentMethodReferenceService::class)->revoke($this->customer, $removed, $this->customer);
        $stranger = $this->saved($this->customer($this->market, ['phone_e164' => '+919876543299']));

        $shown = $this->showCheckout($this->asha, $this->checkout['id'])->json('checkout.payment_methods.saved');
        // Only the usable one, as a safe reference: a label and four digits — no provider reference.
        $this->assertSame([(string) $visa->public_id], array_column($shown, 'id'));
        $this->assertSame(['Visa •••• 4242', '4242'], [$shown[0]['display_label'], $shown[0]['last4']]);
        $this->assertStringNotContainsString('dev_pm_', (string) json_encode($shown));
        $this->assertStringNotContainsString('dev_cust_1', (string) json_encode($shown));

        $with = fn (CustomerPaymentMethod $method, string $type = 'CARD') => $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => ['type' => $type, 'saved_method_id' => (string) $method->public_id]]);
        $with($stranger)->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        $with($expired)->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        $with($removed)->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        $with($visa, 'UPI')->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);

        $ready = $with($visa)->assertOk()->json('checkout');
        $this->assertSame(['type' => 'CARD', 'saved_method_id' => (string) $visa->public_id], $ready['payment_methods']['selected']);
        $this->assertSame((int) $visa->getKey(), (int) $this->sessionModel($ready['id'])->customer_payment_method_id);
        $this->assertStringNotContainsString('dev_pm_', (string) json_encode($ready));
    }

    /* ------------------------------------------------------------------ payment preparation */

    public function test_the_preparation_is_for_exactly_the_checkouts_total_and_is_bound_to_its_price(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        $this->feeRule();
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 500]]);
        $draft = $this->openCheckout($this->asha, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout');
        // ₹500 − ₹100 + ₹15 fee + 5 % test tax on ₹400 = ₹435.
        $this->assertSame($this->inr(43500), $draft['pricing']['total']);

        $ready = $this->confirmCheckout($this->asha, $draft)->assertOk()->json('checkout');
        $payment = $ready['payment'];

        $this->assertSame(['status', 'checkout_id', 'checkout_version', 'amount', 'currency', 'pricing_fingerprint', 'idempotency_key', 'payment_method', 'expires_at', 'provider', 'attempt'], array_keys($payment));
        $this->assertNull($payment['attempt']);   // no payment has been started for it
        $this->assertSame(['READY_FOR_PAYMENT', $ready['id'], $ready['version'], $this->inr(43500), 'INR'], [$payment['status'], $payment['checkout_id'], $payment['checkout_version'], $payment['amount'], $payment['currency']]);
        $this->assertSame([$ready['pricing']['fingerprint'], $ready['expires_at'], ['status' => 'READY', 'name' => 'fake', 'mode' => 'FAKE']], [$payment['pricing_fingerprint'], $payment['expires_at'], $payment['provider']]);
        $this->assertMatchesRegularExpression('/\Apay_[0-9a-f]{40}\z/', $payment['idempotency_key']);
        // The same checkout always prepares the same payment: a retry cannot become a second one.
        $this->assertSame($payment, $this->showCheckout($this->asha, $ready['id'])->json('checkout.payment'));
        $this->assertSame($payment, $this->confirmCheckout($this->asha, $ready)->assertOk()->json('checkout.payment'));

        // Another price is another checkout and another payment.
        $this->deleteJson('/api/v1/checkouts/'.$ready['id'].'/promotion', [], $this->using($this->asha))->assertOk()->assertJsonPath('checkout.payment', null);
        $second = $this->confirmCheckout($this->asha, $this->showCheckout($this->asha, $ready['id'])->json('checkout'))->assertOk()->json('checkout.payment');
        $this->assertSame($this->inr(54000), $second['amount']);
        $this->assertNotSame($payment['idempotency_key'], $second['idempotency_key']);
        $this->assertNotSame($payment['pricing_fingerprint'], $second['pricing_fingerprint']);
    }

    public function test_a_total_of_zero_needs_no_payment_and_no_payment_method(): void
    {
        $this->promotion(['code' => 'FREEMEAL', 'amount_minor' => 99900]);
        $draft = $this->openCheckout($this->asha, ['promotion_code' => 'FREEMEAL'])->assertCreated()->json('checkout');
        $this->assertSame([$this->inr(0), false, $this->inr(50000)], [$draft['pricing']['total'], $draft['pricing']['payment_required'], $draft['pricing']['discount_total']]);

        // Confirmed without a way to pay — and one that is sent anyway is not kept.
        $ready = $this->postJson('/api/v1/checkouts/'.$draft['id'].'/confirm', [
            'pricing_fingerprint' => $draft['pricing']['fingerprint'],
            'accepted_documents' => array_map(fn (array $d): array => ['type' => $d['type'], 'version' => $d['version']], $draft['legal']['required']),
            'payment_method' => ['type' => 'CARD'],
        ], $this->using($this->asha))->assertOk()->json('checkout');

        $this->assertSame('READY', $ready['status']);
        $this->assertSame(['PAYMENT_NOT_REQUIRED', $this->inr(0), null], [$ready['payment']['status'], $ready['payment']['amount'], $ready['payment']['payment_method']]);
        $this->assertNull($ready['payment_methods']['selected']);
        // It is still a confirmed checkout like any other: terms accepted, pickup time held.
        $this->assertSame(3, CheckoutLegalAcceptance::query()->count());
        $this->assertTrue($ready['pickup']['hold']['held']);
    }

    /* ------------------------------------------------------------------ the hand-over to the payment module */

    public function test_starting_a_payment_locks_the_checkout_the_cart_and_the_pickup_time(): void
    {
        $ready = $this->confirmCheckout($this->asha, $this->checkout)->assertOk()->json('checkout');
        $checkouts = app(CheckoutService::class);

        // What Module 30 will call. Nothing is sent to any provider by it.
        $preparation = $checkouts->beginPayment($this->customer, $ready['id'], $ready['pricing']['fingerprint']);

        $this->assertInstanceOf(PaymentPreparation::class, $preparation);
        $this->assertSame([PaymentPreparation::READY, 50000, 'INR', $ready['payment']['idempotency_key']], [$preparation->status, $preparation->amount->amount, $preparation->amount->currency, $preparation->idempotencyKey]);
        $session = $this->sessionModel($ready['id']);
        $this->assertSame('PAYMENT_PENDING', $session->status->value);
        $this->assertTrue($session->payment_started_at->equalTo(now()));

        // The customer sees that a payment is in progress, with the same preparation.
        $shown = $this->showCheckout($this->asha, $ready['id'])->assertOk()->json('checkout');
        $this->assertSame(['PAYMENT_PENDING', $ready['payment']], [$shown['status'], $shown['payment']]);
        $this->assertTrue($shown['pickup']['hold']['held']);

        // Until it ends nothing it was confirmed for can change.
        $locked = fn ($response) => $response->assertConflict()->assertJsonPath('error.code', 'cart_locked_for_payment');
        $line = $this->cartOf($this->using($this->asha))->json('cart.items.0.id');
        $locked($this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($this->asha)));
        $locked($this->patchJson('/api/v1/cart/items/'.$line, ['quantity' => 5], $this->using($this->asha)));
        $locked($this->deleteJson('/api/v1/cart/items/'.$line, [], $this->using($this->asha)));
        $locked($this->deleteJson('/api/v1/cart', [], $this->using($this->asha)));
        $locked($this->selectSlot($this->using($this->asha), '14:00'));
        $locked($this->deleteJson('/api/v1/cart/pickup-selection', [], $this->using($this->asha)));
        $busy = fn ($response) => $response->assertConflict()->assertJsonPath('error.code', 'payment_in_progress');
        $busy($this->openCheckout($this->asha))->assertJsonPath('error.details.checkout_id', $ready['id']);
        $busy($this->confirmCheckout($this->asha, $ready));
        $busy($this->postJson('/api/v1/checkouts/'.$ready['id'].'/promotion', ['code' => 'ANY'], $this->using($this->asha)));
        $busy($this->postJson('/api/v1/checkouts/'.$ready['id'].'/cancel', [], $this->using($this->asha)));
        // … and a second payment cannot be started for it.
        try {
            $checkouts->beginPayment($this->customer, $ready['id'], $ready['pricing']['fingerprint']);
            $this->fail('A second payment was started.');
        } catch (ApiException $e) {
            $this->assertSame([409, 'payment_in_progress'], [$e->status, $e->errorCode]);
        }

        $this->assertSame(50000, (int) $this->sessionModel($ready['id'])->total_minor);
        $this->assertSame(2, (int) $this->cartOf($this->using($this->asha))->json('cart.item_count'));
        // Housekeeping never touches a checkout that led to a payment — not even after its time.
        $this->travel(45)->minutes();
        $this->assertSame(0, $checkouts->prune()['expired']);
        $this->assertSame('PAYMENT_PENDING', $this->sessionModel($ready['id'])->status->value);
    }

    public function test_a_payment_can_only_be_started_for_a_confirmed_current_checkout_at_its_confirmed_price(): void
    {
        $refused = function (string $id, string $fingerprint, ?Customer $customer = null): ApiException {
            try {
                app(CheckoutService::class)->beginPayment($customer ?? $this->customer, $id, $fingerprint);
            } catch (ApiException $e) {
                return $e;
            }
            $this->fail('A payment was started.');
        };

        // Not confirmed.
        $e = $refused($this->checkout['id'], $this->checkout['pricing']['fingerprint']);
        $this->assertSame([409, 'checkout_not_ready'], [$e->status, $e->errorCode]);

        $ready = $this->confirmCheckout($this->asha, $this->checkout)->assertOk()->json('checkout');
        // Another price than the confirmed one.
        $e = $refused($ready['id'], str_repeat('0', 64));
        $this->assertSame([409, 'checkout_stale'], [$e->status, $e->errorCode]);
        // Somebody else's checkout does not exist.
        $e = $refused($ready['id'], $ready['pricing']['fingerprint'], $this->customer($this->market, ['phone_e164' => '+919876543299']));
        $this->assertSame([404, 'checkout_not_found'], [$e->status, $e->errorCode]);
        $this->assertSame('READY', $this->sessionModel($ready['id'])->status->value);

        // The menu price changed after the confirmation: no payment for the old total — and the checkout is stale for good.
        app(MenuService::class)->updateItem($this->burger->refresh(), ['base_price_minor' => 25000, 'version' => $this->burger->version], $this->staff());
        $e = $refused($ready['id'], $ready['pricing']['fingerprint']);
        $this->assertSame([409, 'checkout_stale'], [$e->status, $e->errorCode]);
        $session = $this->sessionModel($ready['id']);
        $this->assertSame(['STALE', null], [$session->status->value, $session->payment_started_at]);
        $this->assertSame(0, PickupSlotReservation::query()->where('status', 'ACTIVE')->count(), 'its pickup time was given back');

        // A new checkout, confirmed, then left until it expired.
        $next = $this->confirmCheckout($this->asha, $this->openCheckout($this->asha)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->travel(10)->minutes();
        $e = $refused($next['id'], $next['pricing']['fingerprint']);
        $this->assertSame([410, 'checkout_expired'], [$e->status, $e->errorCode]);
        $this->assertSame('EXPIRED', $this->sessionModel($next['id'])->status->value);
    }

    public function test_a_checkout_is_paid_through_a_payment_attempt_and_no_route_creates_an_order_or_a_refund(): void
    {
        $routes = collect(app('router')->getRoutes())->map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri())->filter(fn (string $r): bool => str_contains($r, 'api/v1'));

        // Since Module 30 a confirmed checkout has exactly one payment route: a payment attempt for its total.
        $this->assertSame(['POST api/v1/checkouts/{checkout}/payment-attempts'], $routes->filter(fn (string $r): bool => str_contains($r, '/checkouts') && str_contains($r, 'pay'))->values()->all());
        // Refunds, captures and settlements belong to later modules: nothing of them exists. Orders exist since Module 31 —
        // created by the server from the verified payment; the one order route under /checkouts is for a ZERO total.
        foreach (['refunds', 'capture', 'settlements', 'payment-intents'] as $segment) {
            $this->assertSame([], $routes->filter(fn (string $r): bool => str_contains($r, $segment))->values()->all(), $segment);
        }
        $this->assertSame(['POST api/v1/checkouts/{checkout}/fulfill'], $routes->filter(fn (string $r): bool => str_contains($r, '/checkouts/') && str_contains($r, 'fulfill'))->values()->all());
        $this->assertCount(19, $routes->filter(fn (string $r): bool => str_contains($r, 'orders') || str_contains($r, 'order-fulfillments') || str_contains($r, '/fulfill')));   // 15 (Module 31) + 4 (Module 32: the event streams and the pickup credential routes)
        $ready = $this->confirmCheckout($this->asha, $this->checkout)->assertOk()->json('checkout');
        foreach (['/pay', '/payment', '/payments', '/order', '/capture', '/refund'] as $suffix) {
            $this->postJson('/api/v1/checkouts/'.$ready['id'].$suffix, [], $this->using($this->asha))->assertNotFound();
        }
        foreach (['payment_intents', 'settlements'] as $table) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($table), $table);
        }
        // Orders exist since Module 31; their refund records are a foundation only (nothing is refunded).
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('orders'));
        $this->assertSame(0, DB::table('order_refunds')->count());
    }
}
