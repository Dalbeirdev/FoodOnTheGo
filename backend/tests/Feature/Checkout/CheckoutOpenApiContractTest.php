<?php

namespace Tests\Feature\Checkout;

use App\Enums\MenuItemStatus;
use App\Enums\Permission;
use App\Models\Cart;
use App\Models\FeeRule;
use App\Models\TaxRule;
use App\Services\Checkout\CheckoutService;
use App\Services\Customer\PaymentMethodReferenceService;
use App\Services\Menu\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCheckout;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Every checkout endpoint (Module 29) answers with the shape documented in openapi/openapi.json — in every state:
 * draft, with a promotion, with tax and fees, ready, zero total, stale, expired, cancelled, payment pending, and
 * each error. A property that is not documented fails the test.
 */
class CheckoutOpenApiContractTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase, ValidatesOpenApi;

    public function test_the_checkout_endpoints_match_their_documented_schemas(): void
    {
        $this->setUpCheckoutWorld();
        // One customer walks through every state here — more requests than a customer may send in a minute.
        config(['rate_limits.promotion_apply' => 1000, 'rate_limits.checkout_write' => 1000, 'rate_limits.checkout_read' => 1000]);
        $this->fakeRouting();
        $this->fakePlaces();
        $this->settings(['capacity_per_slot' => 1]);
        $this->promotion(['code' => 'SAVE100']);
        $this->promotion(['code' => 'FREEMEAL', 'amount_minor' => 999900]);
        $this->promotion(['code' => 'ENDED', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->promotion(['code' => 'MIN900', 'minimum_spend_minor' => 90000]);
        $this->promotion(['code' => 'GONE', 'usage_limit' => 1, 'redeemed_count' => 1]);
        [$pizza] = $this->otherRestaurant();
        $this->promotion(['code' => 'PIZZA', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $pizza->getKey()]);
        [$customer, $asha] = $this->signedIn();
        app(PaymentMethodReferenceService::class)->attach($customer, ['type' => 'CARD', 'provider' => 'development', 'provider_customer_reference' => 'dev_cust_1', 'provider_payment_method_reference' => 'dev_pm_1', 'brand' => 'Visa', 'display_label' => 'Visa •••• 4242', 'last4' => '4242', 'expiry_month' => 12, 'expiry_year' => 2028, 'is_default' => true]);
        $url = fn (string $id, string $suffix = '') => '/api/v1/checkouts/'.$id.$suffix;
        $errors = [];

        // ── Before there is anything to check out
        $errors[] = $this->openCheckout($asha)->assertStatus(404)->assertJsonPath('error.code', 'cart_not_found');
        $this->addBurger($asha, 2, ['Size' => ['Large'], 'Add-ons' => ['Extra Cheese']], ['special_instructions' => 'No onions'])->assertCreated();
        $errors[] = $this->openCheckout($asha)->assertStatus(409)->assertJsonPath('error.code', 'pickup_required');
        $this->arriveAt($asha, '13:20');
        $this->selectSlot($this->using($asha), '13:15')->assertOk();

        // ── Draft: plain, replayed, reused, with tax and fees, with a promotion
        $key = ['Idempotency-Key' => 'checkout-contract-0001'];
        $plain = $this->openCheckout([...$asha, ...$key])->assertCreated();
        $this->assertMatchesSchema('CheckoutEnvelope', $plain->json());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->openCheckout([...$asha, ...$key])->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->openCheckout($asha)->assertOk()->json());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($asha, $plain->json('checkout.id'))->assertOk()->json());
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 250], ['TEST_TAX_B', 'Test tax B', 250]]);
        $this->taxRule([['TEST_FEE_TAX', 'Test tax on fees', 1800]], ['applies_to' => 'FEES', 'price_treatment' => 'INCLUSIVE', 'name' => 'TEST TAX CONFIGURATION (fees)']);
        $this->feeRule(['taxable' => true]);
        $this->feeRule(['code' => 'PACKAGING', 'label' => 'Test packaging charge', 'type' => 'PERCENTAGE', 'percent_basis_points' => 200, 'minimum_minor' => 500, 'maximum_minor' => 3000]);
        $stale = $this->showCheckout($asha, $plain->json('checkout.id'))->assertOk()->assertJsonPath('checkout.status', 'STALE');
        $this->assertMatchesSchema('CheckoutEnvelope', $stale->json());
        $priced = $this->openCheckout($asha, ['promotion_code' => 'save100', 'cart_version' => (int) $this->cartOf($this->using($asha))->json('cart.version')])->assertCreated();
        $this->assertMatchesSchema('CheckoutEnvelope', $priced->json());
        $this->assertCount(3, $priced->json('checkout.pricing.taxes'));
        $this->assertCount(2, $priced->json('checkout.pricing.fees'));
        $id = $priced->json('checkout.id');
        $this->assertMatchesSchema('CheckoutEnvelope', $this->deleteJson($url($id, '/promotion'), [], $this->using($asha))->assertOk()->json());
        $draft = $this->postJson($url($id, '/promotion'), ['code' => 'SAVE100'], $this->using($asha))->assertOk();
        $this->assertMatchesSchema('CheckoutEnvelope', $draft->json());

        // ── Errors of a promotion code, of opening and of confirming
        foreach (['NOSUCH' => 'promotion_not_found', 'ENDED' => 'promotion_expired', 'MIN900' => 'promotion_minimum_not_met', 'GONE' => 'promotion_limit_reached', 'PIZZA' => 'promotion_not_applicable'] as $code => $error) {
            $errors[] = $this->postJson($url($id, '/promotion'), ['code' => $code], $this->using($asha))->assertStatus(422)->assertJsonPath('error.code', $error);
        }
        $errors[] = $this->openCheckout($asha, ['promotion_code' => 'ENDED'])->assertStatus(422)->assertJsonPath('error.code', 'promotion_expired');
        $errors[] = $this->openCheckout($asha, ['cart_version' => 99])->assertStatus(409)->assertJsonPath('error.code', 'cart_version_conflict');
        $errors[] = $this->openCheckout($asha, ['total' => 1])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $errors[] = $this->openCheckout([...$asha, 'Idempotency-Key' => 'x'])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $errors[] = $this->openCheckout([...$asha, ...$key], ['promotion_code' => 'SAVE100'])->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $errors[] = $this->postJson($url($id, '/promotion'), [], $this->using($asha))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $errors[] = $this->showCheckout($asha, '0199c9d2-7f7b-7c8a-9d57-3d5e6f708192')->assertStatus(404)->assertJsonPath('error.code', 'checkout_not_found');
        $this->app['auth']->forgetGuards();
        $errors[] = $this->postJson('/api/v1/checkouts', [])->assertStatus(401);
        $errors[] = $this->confirmCheckout($asha, $draft->json('checkout'), ['accepted_documents' => []])->assertStatus(422)->assertJsonPath('error.code', 'legal_acceptance_required');
        $errors[] = $this->confirmCheckout($asha, $draft->json('checkout'), ['payment_method' => null])->assertStatus(422)->assertJsonPath('error.code', 'payment_method_required');
        $errors[] = $this->confirmCheckout($asha, $draft->json('checkout'), ['payment_method' => ['type' => 'CASH_AT_PICKUP']])->assertStatus(422)->assertJsonPath('error.code', 'payment_method_unavailable');
        $errors[] = $this->confirmCheckout($asha, $draft->json('checkout'), ['pricing_fingerprint' => str_repeat('0', 64)])->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');
        $errors[] = $this->confirmCheckout($asha, $draft->json('checkout'), ['cvv' => '123'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        // ── Ready (with a saved method and a note), read, confirmed again, a promotion change takes it back to draft
        $saved = $draft->json('checkout.payment_methods.saved.0.id');
        $ready = $this->confirmCheckout([...$asha, 'Idempotency-Key' => 'checkout-contract-0002'], $draft->json('checkout'), ['payment_method' => ['type' => 'CARD', 'saved_method_id' => $saved], 'order_note' => 'Please pack the sauce separately.'])->assertOk()->assertJsonPath('checkout.status', 'READY');
        $this->assertMatchesSchema('CheckoutEnvelope', $ready->json());
        $this->assertMatchesSchema('CheckoutPayment', $ready->json('checkout.payment'));
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($asha, $id)->assertOk()->assertJsonPath('checkout.pickup.hold.held', true)->json());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->confirmCheckout($asha, $ready->json('checkout'))->assertOk()->json());

        // ── Another customer: the slot is held, so their checkout cannot be opened for it
        [, $ravi] = $this->signedIn('+919876543211');
        $this->addBurger($ravi)->assertCreated();
        $errors[] = $this->postJson('/api/v1/cart/pickup-selection', ['mode' => 'SCHEDULED', 'slot_key' => $this->slotKey($ravi, '13:30')], $ravi)->assertOk() ? $this->showCheckout($ravi, $id)->assertStatus(404)->assertJsonPath('error.code', 'checkout_not_found') : null;
        $raviDraft = $this->openCheckout($ravi)->assertCreated();
        $this->assertMatchesSchema('CheckoutEnvelope', $raviDraft->json());

        // ── A change of the promotion takes a confirmed checkout back to draft. A free meal still owes the configured fees.
        $back = $this->postJson($url($id, '/promotion'), ['code' => 'FREEMEAL'], $this->using($asha))->assertOk()->assertJsonPath('checkout.status', 'DRAFT')->assertJsonPath('checkout.payment', null)->assertJsonPath('checkout.pricing.payment_required', true);
        $this->assertMatchesSchema('CheckoutEnvelope', $back->json());

        // ── Zero total (no fee and no tax rule in force): no payment needed
        FeeRule::query()->update(['status' => 'DISABLED']);
        TaxRule::query()->update(['status' => 'DISABLED']);
        $free = $this->openCheckout($asha)->assertCreated()->assertJsonPath('checkout.promotion.code', 'FREEMEAL')->assertJsonPath('checkout.pricing.payment_required', false);
        $this->assertMatchesSchema('CheckoutEnvelope', $free->json());
        $id = $free->json('checkout.id');
        $freeReady = $this->confirmCheckout($asha, $free->json('checkout'))->assertOk()->assertJsonPath('checkout.payment.status', 'PAYMENT_NOT_REQUIRED');
        $this->assertMatchesSchema('CheckoutEnvelope', $freeReady->json());

        // ── Cancelled, and the errors of a closed checkout
        $this->assertMatchesSchema('CheckoutEnvelope', $this->postJson($url($id, '/cancel'), [], $this->using($asha))->assertOk()->assertJsonPath('checkout.status', 'CANCELLED')->json());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->postJson($url($id, '/cancel'), [], $this->using($asha))->assertOk()->json());
        $errors[] = $this->confirmCheckout($asha, $freeReady->json('checkout'))->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');
        $errors[] = $this->postJson($url($id, '/promotion'), ['code' => 'SAVE100'], $this->using($asha))->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');

        // ── Stale by a price change (with the change told on the next checkout), expired, payment pending
        $next = $this->confirmCheckout($asha, $this->openCheckout($asha)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        app(MenuService::class)->updateItem($this->burger->refresh(), ['base_price_minor' => 21000, 'version' => $this->burger->version], $this->staff());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($asha, $next['id'])->assertOk()->assertJsonPath('checkout.status', 'STALE')->assertJsonPath('checkout.issues.0.code', 'PRICE_CHANGED')->json());
        $errors[] = $this->confirmCheckout($asha, $next)->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');
        $changed = $this->openCheckout($asha)->assertCreated();
        $this->assertSame('PRICE_CHANGED', $changed->json('checkout.changes.0.code'));
        $this->assertMatchesSchema('CheckoutEnvelope', $changed->json());
        $again = $this->confirmCheckout($asha, $changed->json('checkout'))->assertOk()->json('checkout');
        app(CheckoutService::class)->beginPayment($customer, $again['id'], $again['pricing']['fingerprint']);
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($asha, $again['id'])->assertOk()->assertJsonPath('checkout.status', 'PAYMENT_PENDING')->json());
        foreach ([$this->openCheckout($asha), $this->confirmCheckout($asha, $again), $this->postJson($url($again['id'], '/cancel'), [], $this->using($asha)), $this->deleteJson($url($again['id'], '/promotion'), [], $this->using($asha))] as $busy) {
            $errors[] = $busy->assertStatus(409)->assertJsonPath('error.code', 'payment_in_progress');
        }
        $errors[] = $this->postJson('/api/v1/cart/items', $this->addBody($this->fries), $this->using($asha))->assertStatus(409)->assertJsonPath('error.code', 'cart_locked_for_payment');

        // (Ravi's draft from before is stale by now — the rules and the price changed — so he starts again.)
        $errors[] = $this->confirmCheckout($ravi, $raviDraft->json('checkout'))->assertStatus(409)->assertJsonPath('error.code', 'checkout_stale');
        $raviReady = $this->confirmCheckout($ravi, $this->openCheckout($ravi)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->travel(11)->minutes();
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($ravi, $raviReady['id'])->assertOk()->assertJsonPath('checkout.status', 'EXPIRED')->json());
        $errors[] = $this->confirmCheckout($ravi, $raviReady)->assertStatus(410)->assertJsonPath('error.code', 'checkout_expired');

        // ── What blocks opening a checkout
        app(MenuService::class)->setItemStatus($this->burger->refresh(), MenuItemStatus::SoldOut, $this->staff());
        $errors[] = $this->openCheckout($ravi)->assertStatus(409)->assertJsonPath('error.code', 'item_unavailable');
        app(MenuService::class)->setItemStatus($this->burger->refresh(), MenuItemStatus::Active, $this->staff());
        $this->burgerHub->forceFill(['accepting_orders' => false, 'paused_until' => now()->addHour()])->save();
        $errors[] = $this->openCheckout($ravi)->assertStatus(409)->assertJsonPath('error.code', 'restaurant_not_accepting');
        $this->burgerHub->forceFill(['accepting_orders' => true, 'paused_until' => null])->save();
        Cart::query()->where('customer_id', '!=', $customer->getKey())->active()->update(['expires_at' => now()->subMinute()]);
        $errors[] = $this->openCheckout($ravi)->assertStatus(410)->assertJsonPath('error.code', 'cart_expired');

        foreach (array_filter($errors) as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }
        $this->assertGreaterThanOrEqual(30, count(array_filter($errors)));
    }

    public function test_the_admin_endpoints_match_their_documented_schemas(): void
    {
        $this->setUpCheckoutWorld();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminPromotionsView, Permission::AdminPromotionsManage, Permission::AdminMarketConfigurationView, Permission::AdminMarketConfigurationManage, Permission::AdminSystemView]));
        $errors = [];

        // ── Promotions
        $platform = $this->postJson('/api/v1/admin/promotions', ['code' => 'WELCOME100', 'name' => '₹100 off', 'type' => 'FIXED_AMOUNT', 'scope' => 'PLATFORM', 'currency' => 'INR', 'amount_minor' => 10000, 'reason' => 'Launch'])->assertCreated();
        $this->assertMatchesSchema('AdminPromotionEnvelope', $platform->json());
        $local = $this->postJson('/api/v1/admin/promotions', ['code' => 'BURGER20', 'name' => '20 % off', 'description' => 'Burger Hub only', 'type' => 'PERCENTAGE', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $this->burgerHub->public_id, 'percent_basis_points' => 2000, 'currency' => 'INR', 'maximum_discount_minor' => 10000, 'minimum_spend_minor' => 20000, 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(30)->toIso8601String(), 'usage_limit' => 100, 'per_customer_limit' => 1, 'funding_source' => 'RESTAURANT'])->assertCreated();
        $this->assertMatchesSchema('AdminPromotionEnvelope', $local->json());
        $id = $platform->json('promotion.id');
        $this->assertMatchesSchema('AdminPromotionEnvelope', $this->getJson('/api/v1/admin/promotions/'.$id)->assertOk()->json());
        $this->assertMatchesSchema('AdminPromotionEnvelope', $this->patchJson('/api/v1/admin/promotions/'.$id, ['name' => '₹100 off your order', 'version' => 1, 'reason' => 'Clearer'])->assertOk()->json());
        $active = $this->postJson('/api/v1/admin/promotions/'.$id.'/status', ['status' => 'ACTIVE', 'version' => 2, 'reason' => 'Start'])->assertOk();
        $this->assertMatchesSchema('AdminPromotionEnvelope', $active->json());
        $this->postJson('/api/v1/admin/promotions/'.$local->json('promotion.id').'/status', ['status' => 'ACTIVE', 'version' => 1, 'reason' => 'Start'])->assertOk()->assertJsonPath('promotion.effective_status', 'SCHEDULED');
        $this->assertMatchesSchema('AdminPromotionPage', $this->getJson('/api/v1/admin/promotions?sort=code&filter[status]=ACTIVE&page[size]=10')->assertOk()->assertJsonPath('meta.total', 1)->json());
        $this->assertMatchesSchema('AdminPromotionPage', $this->getJson('/api/v1/admin/promotions?q=nothing')->assertOk()->assertJsonPath('data', [])->json());
        $errors[] = $this->postJson('/api/v1/admin/promotions', ['code' => 'welcome100', 'name' => 'Again', 'type' => 'FIXED_AMOUNT', 'scope' => 'PLATFORM', 'currency' => 'INR', 'amount_minor' => 1])->assertStatus(409)->assertJsonPath('error.code', 'promotion_code_taken');
        $errors[] = $this->postJson('/api/v1/admin/promotions', ['code' => 'X'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $errors[] = $this->patchJson('/api/v1/admin/promotions/'.$id, ['amount_minor' => 5, 'version' => 3, 'reason' => 'Change'])->assertStatus(409)->assertJsonPath('error.code', 'promotion_live_edit_refused');
        $errors[] = $this->patchJson('/api/v1/admin/promotions/'.$id, ['name' => 'Y', 'version' => 1, 'reason' => 'Old'])->assertStatus(422);
        $errors[] = $this->patchJson('/api/v1/admin/promotions/'.$id, ['name' => 'Stale name', 'version' => 1, 'reason' => 'Old'])->assertStatus(409)->assertJsonPath('error.code', 'stale_update');
        $errors[] = $this->postJson('/api/v1/admin/promotions/'.$id.'/status', ['status' => 'DRAFT', 'version' => 3, 'reason' => 'Back'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');
        $errors[] = $this->getJson('/api/v1/admin/promotions/0199c9d2-7f7b-7c8a-9d57-3d5e6f708192')->assertStatus(404);
        $errors[] = $this->getJson('/api/v1/admin/promotions?filter[status]=NOPE')->assertStatus(422);

        // ── Tax and fee rules
        $rules = '/api/v1/admin/markets/'.$this->market->public_id;
        $this->assertMatchesSchema('FinancialRules', $this->getJson($rules.'/financial-rules')->assertOk()->json());
        $tax = $this->postJson($rules.'/tax-rules', ['name' => 'TEST TAX CONFIGURATION', 'applies_to' => 'MERCHANDISE', 'price_treatment' => 'EXCLUSIVE', 'discount_treatment' => 'BEFORE_DISCOUNT', 'effective_from' => now()->subHour()->toIso8601String(), 'effective_until' => now()->addYear()->toIso8601String(), 'location_id' => $this->burgerHub->public_id, 'components' => [['code' => 'TEST_TAX_A', 'label' => 'Test tax A', 'rate_basis_points' => 250]], 'reason' => 'Development'])->assertCreated();
        $this->assertMatchesSchema('TaxRuleEnvelope', $tax->json());
        $taxId = $tax->json('tax_rule.id');
        $this->assertMatchesSchema('TaxRuleEnvelope', $this->patchJson('/api/v1/admin/tax-rules/'.$taxId, ['components' => [['code' => 'TEST_TAX_A', 'label' => 'Test tax A', 'rate_basis_points' => 500]], 'location_id' => null, 'version' => 1, 'reason' => 'Rate'])->assertOk()->json());
        $this->assertMatchesSchema('TaxRuleEnvelope', $this->postJson('/api/v1/admin/tax-rules/'.$taxId.'/status', ['status' => 'ACTIVE', 'version' => 2, 'reason' => 'Switch on'])->assertOk()->json());
        $fee = $this->postJson($rules.'/fee-rules', ['code' => 'PACKAGING', 'label' => 'Test packaging charge', 'type' => 'PERCENTAGE', 'percent_basis_points' => 200, 'percent_of' => 'DISCOUNTED_SUBTOTAL', 'currency' => 'INR', 'minimum_minor' => 500, 'maximum_minor' => 3000, 'pickup_method' => 'COUNTER', 'taxable' => true, 'effective_from' => now()->subHour()->toIso8601String(), 'location_id' => $this->burgerHub->public_id, 'reason' => 'Development'])->assertCreated();
        $this->assertMatchesSchema('FeeRuleEnvelope', $fee->json());
        $feeId = $fee->json('fee_rule.id');
        $fixed = $this->postJson($rules.'/fee-rules', ['code' => 'PLATFORM_FEE', 'label' => 'Test platform fee', 'type' => 'FIXED', 'currency' => 'INR', 'amount_minor' => 1500, 'effective_from' => now()->subHour()->toIso8601String(), 'reason' => 'Development'])->assertCreated();
        $this->assertMatchesSchema('FeeRuleEnvelope', $fixed->json());
        $this->assertMatchesSchema('FeeRuleEnvelope', $this->patchJson('/api/v1/admin/fee-rules/'.$feeId, ['maximum_minor' => 2500, 'version' => 1, 'reason' => 'Cap'])->assertOk()->json());
        $this->assertMatchesSchema('FeeRuleEnvelope', $this->postJson('/api/v1/admin/fee-rules/'.$feeId.'/status', ['status' => 'ACTIVE', 'version' => 2, 'reason' => 'Switch on'])->assertOk()->json());
        $this->assertMatchesSchema('FinancialRules', $this->getJson($rules.'/financial-rules')->assertOk()->assertJsonCount(1, 'tax_rules')->assertJsonCount(2, 'fee_rules')->json());
        $errors[] = $this->postJson($rules.'/fee-rules', ['code' => 'DELIVERY_FEE', 'label' => 'Delivery', 'type' => 'FIXED', 'currency' => 'INR', 'amount_minor' => 4000, 'effective_from' => now()->toIso8601String(), 'reason' => 'Try'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $errors[] = $this->postJson($rules.'/tax-rules', ['name' => 'X'])->assertStatus(422);
        $errors[] = $this->patchJson('/api/v1/admin/tax-rules/'.$taxId, ['name' => 'Renamed', 'version' => 3, 'reason' => 'Rename'])->assertStatus(409)->assertJsonPath('error.code', 'rule_frozen');
        $errors[] = $this->patchJson('/api/v1/admin/fee-rules/'.$feeId, ['label' => 'Renamed', 'version' => 1, 'reason' => 'Rename'])->assertStatus(409)->assertJsonPath('error.code', 'stale_update');
        $errors[] = $this->postJson('/api/v1/admin/fee-rules/'.$feeId.'/status', ['status' => 'DRAFT', 'version' => 3, 'reason' => 'Back'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');
        $errors[] = $this->getJson('/api/v1/admin/markets/0199c9d2-7f7b-7c8a-9d57-3d5e6f708192/financial-rules')->assertStatus(404);

        // ── Numbers
        [, $asha] = $this->customerReadyForCheckout();
        $this->confirmCheckout($asha, $this->openCheckout($asha, ['promotion_code' => 'WELCOME100'])->assertCreated()->json('checkout'))->assertOk();
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $metrics = $this->getJson('/api/v1/admin/system/checkout')->assertOk();
        $this->assertMatchesSchema('CheckoutMetrics', $metrics->json());
        $this->assertSame([1, 1], [$metrics->json('events.confirmed'), $metrics->json('active_pickup_holds')]);
        $this->assertMatchesSchema('PickupMetrics', $this->getJson('/api/v1/admin/system/pickup')->assertOk()->assertJsonPath('slot_hold', 'AT_CHECKOUT')->assertJsonPath('upcoming_capacity_units.hold', 1)->json());
        $errors[] = $this->getJson('/api/v1/admin/promotions')->assertStatus(403);

        foreach ($errors as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }
    }

    public function test_every_checkout_route_is_documented_and_no_request_can_carry_an_amount_or_a_credential(): void
    {
        $api = $this->openApi();
        $paths = $api['paths'];
        // The payment route under a checkout belongs to the payment module (Module 30) and has its own contract test.
        $mine = fn (string $uri): bool => ! str_contains($uri, 'payment-attempts') && (bool) preg_match('#^(checkouts|admin/promotions|admin/tax-rules|admin/fee-rules|admin/system/checkout)|/(financial-rules|tax-rules|fee-rules)$#', $uri);
        $documented = [];
        foreach ($paths as $path => $operations) {
            if ($mine(ltrim($path, '/'))) {
                foreach (array_keys($operations) as $method) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => [preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])])->filter(fn (array $r): bool => $mine($r[0]))
            ->flatMap(fn (array $r) => array_map(fn (string $m): string => $m.' /'.$r[0], $r[1]))->values()->all();
        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(20, $routes);   // 19 of Modules 29 / 30 + POST /checkouts/{checkout}/fulfill (Module 31, zero-total orders)

        // A customer operation names nothing but the checkout: the cart, the restaurant and the customer are the caller's.
        foreach (['/checkouts', '/checkouts/{checkout}', '/checkouts/{checkout}/promotion', '/checkouts/{checkout}/confirm', '/checkouts/{checkout}/cancel'] as $path) {
            foreach ($paths[$path] as $operation) {
                $this->assertSame([['customer' => []]], $operation['security']);
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $this->assertContains($parameter['name'] ?? basename($parameter['$ref']), ['checkout', 'IdempotencyKeyOptional']);
                }
            }
        }
        // The request bodies are closed, and none of them has a field for an amount, a currency, an owner, a state or a credential.
        $schemas = $api['components']['schemas'];
        $this->assertSame(['promotion_code', 'cart_version'], array_keys($schemas['CheckoutOpenRequest']['properties']));
        $this->assertSame(['code'], array_keys($schemas['CheckoutPromotionRequest']['properties']));
        $this->assertSame(['pricing_fingerprint', 'accepted_documents', 'payment_method', 'order_note'], array_keys($schemas['CheckoutConfirmRequest']['properties']));
        $this->assertSame(['type', 'saved_method_id'], array_keys($schemas['CheckoutConfirmRequest']['properties']['payment_method']['properties']));
        foreach (['CheckoutOpenRequest', 'CheckoutPromotionRequest', 'CheckoutConfirmRequest'] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties']);
        }
        $this->assertFalse($schemas['CheckoutConfirmRequest']['properties']['payment_method']['additionalProperties']);
        // What is documented as not existing: cash at pickup. A provider is either ready here or it is not (Module 30).
        $this->assertSame(['NOT_APPROVED'], $schemas['Checkout']['properties']['payment_methods']['properties']['cash_at_pickup']['enum']);
        $this->assertSame(['READY', 'UNAVAILABLE'], $schemas['CheckoutPayment']['properties']['provider']['properties']['status']['enum']);
        $this->assertSame(['IMPLEMENTED'], $schemas['CheckoutMetrics']['properties']['slot_hold']['enum']);
        $this->assertSame(['FIXED_AMOUNT', 'PERCENTAGE'], $schemas['AdminPromotion']['properties']['type']['enum']);
        // Payments exist since Module 30 and orders since Module 31 (created on the server from the verified payment).
        // Refunds exist since Module 35 — as administrator routes only (never under a checkout or a customer path).
        // Settlements exist since Module 36 — as finance routes only.
        foreach (array_keys($paths) as $path) {
            // Module 36: settlements exist since then — only as finance paths (administrators, restaurants, the
            // development simulator), never under a checkout, a payment or a customer path.
            if (preg_match('#settlements(/|$)#', $path) === 1) {
                $this->assertMatchesRegularExpression('#^/(admin/finance|restaurant/finance|dev/finance)/#', $path, 'a settlement route is a finance route');
            }
            if (preg_match('#/refunds(/|$)#', $path) === 1) {
                $this->assertMatchesRegularExpression('#^/(admin|dev)/#', $path, 'a refund route is an administrator route');
            }
        }
        $this->assertArrayHasKey('/customer/orders/{order}', $paths);
        // Every monetary field of the checkout is Money (integer minor units + currency) or an integer; no float anywhere.
        $numbers = [];
        array_walk_recursive($schemas['CheckoutPricing'], function ($value, $key) use (&$numbers): void {
            if ($key === 'type' && $value === 'number') {
                $numbers[] = $value;
            }
        });
        $this->assertSame([], $numbers);
    }
}
