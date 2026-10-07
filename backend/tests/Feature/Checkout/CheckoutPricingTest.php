<?php

namespace Tests\Feature\Checkout;

use App\Models\FeeRule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * The authoritative price of a checkout (Module 29): subtotal − discount + configured fees + configured tax, in
 * integers, with one rounding policy. Every rate and amount in these tests is a TEST fixture of the engine — none is
 * an official tax rate or an approved fee of any country.
 */
class CheckoutPricingTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingOf(int $quantity = 2, array $chosen = ['Size' => ['Large']], array $body = []): array
    {
        [, $headers] = $this->customerReadyForCheckout($quantity, $chosen);

        return $this->openCheckout($headers, $body)->assertCreated()->json('checkout.pricing');
    }

    public function test_without_a_configured_rule_no_tax_and_no_fee_exist_and_nothing_is_invented(): void
    {
        $pricing = $this->pricingOf();

        $this->assertSame([], $pricing['taxes']);
        $this->assertSame([], $pricing['fees']);
        $this->assertSame($this->inr(50000), $pricing['total']);
        $this->assertSame(['tax' => 'NOT_CONFIGURED', 'fees' => 'NOT_CONFIGURED'], $pricing['configuration']);
        // A pickup product has no delivery fee line, a placeholder ₹0 line included.
        $this->assertStringNotContainsStringIgnoringCase('delivery', json_encode($pricing));
        $this->assertStringNotContainsStringIgnoringCase('gst', json_encode($pricing));
    }

    public function test_a_fixed_discount_comes_off_the_merchandise_subtotal(): void
    {
        $this->promotion(['code' => 'SAVE100', 'name' => '₹100 off', 'amount_minor' => 10000]);

        $pricing = $this->pricingOf(2, ['Size' => ['Large']], ['promotion_code' => 'SAVE100']);

        $this->assertSame($this->inr(50000), $pricing['items_subtotal']);
        $this->assertSame([['code' => 'SAVE100', 'label' => '₹100 off', 'amount' => $this->inr(10000)]], $pricing['discounts']);
        $this->assertSame($this->inr(40000), $pricing['total']);
    }

    public function test_a_percentage_discount_is_exact_and_capped_by_its_maximum(): void
    {
        $this->promotion(['code' => 'TEN', 'type' => 'PERCENTAGE', 'percent_basis_points' => 1000]);
        $this->promotion(['code' => 'TWENTY', 'type' => 'PERCENTAGE', 'percent_basis_points' => 2000, 'maximum_discount_minor' => 10000]);

        // ₹500 − 10 % = ₹50 off.
        $ten = $this->pricingOf(2, ['Size' => ['Large']], ['promotion_code' => 'TEN']);
        $this->assertSame($this->inr(5000), $ten['discount_total']);
        $this->assertSame($this->inr(45000), $ten['total']);

        // ₹1,000 − 20 % would be ₹200; the maximum is ₹100.
        [, $headers] = $this->customerReadyForCheckout(4, ['Size' => ['Large']], null, '+919876543211');
        $twenty = $this->openCheckout($headers, ['promotion_code' => 'TWENTY'])->assertCreated()->json('checkout.pricing');
        $this->assertSame($this->inr(100000), $twenty['items_subtotal']);
        $this->assertSame($this->inr(10000), $twenty['discount_total']);
        $this->assertSame($this->inr(90000), $twenty['total']);
    }

    public function test_a_percentage_is_rounded_half_up_to_the_minor_unit_without_floating_point(): void
    {
        // Burger Regular ₹200 + Extra Cheese ₹20 = ₹220.00; 3 × = ₹660.00. 33.33 % of 66000 = 21997.8 → 21998.
        $this->promotion(['code' => 'THIRD', 'type' => 'PERCENTAGE', 'percent_basis_points' => 3333]);
        $pricing = $this->pricingOf(3, ['Size' => ['Regular'], 'Add-ons' => ['Extra Cheese']], ['promotion_code' => 'THIRD']);
        $this->assertSame($this->inr(66000), $pricing['items_subtotal']);
        $this->assertSame($this->inr(21998), $pricing['discount_total']);
        $this->assertSame($this->inr(44002), $pricing['total']);

        // 0.5 goes up: 12.5 % of ₹2.20 is not reachable here, so the half is produced by tax — 7.25 % of ₹440.02 = 3190.145 → 3190;
        // 2.5 % of ₹440.02 = 1100.05 → 1100; 12.5 % of 44002 = 5500.25 → 5500. And an exact half: 0.01 % of ₹5,000.00 = 50.00.
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 725], ['TEST_TAX_B', 'Test tax B', 250], ['TEST_TAX_C', 'Test tax C', 1250]]);
        [, $headers] = $this->customerReadyForCheckout(3, ['Size' => ['Regular'], 'Add-ons' => ['Extra Cheese']], null, '+919876543211');
        $taxed = $this->openCheckout($headers, ['promotion_code' => 'THIRD'])->assertCreated()->json('checkout.pricing');
        $this->assertSame([3190, 1100, 5500], array_map(fn (array $t): int => $t['amount']['amount'], $taxed['taxes']));
        $this->assertSame($this->inr(44002 + 3190 + 1100 + 5500), $taxed['total']);
        foreach (['items_subtotal', 'discount_total', 'tax_total', 'fee_total', 'total'] as $money) {
            $this->assertIsInt($taxed[$money]['amount']);
        }
    }

    public function test_tax_added_at_checkout_with_several_components(): void
    {
        // TEST TAX CONFIGURATION: two components of 2.5 % each, added on top of the prices.
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 250], ['TEST_TAX_B', 'Test tax B', 250]]);

        $pricing = $this->pricingOf();

        $this->assertSame([
            ['code' => 'TEST_TAX_A', 'label' => 'Test tax A', 'applies_to' => 'MERCHANDISE', 'rate_basis_points' => 250, 'taxable_amount' => $this->inr(50000), 'amount' => $this->inr(1250), 'included_in_prices' => false],
            ['code' => 'TEST_TAX_B', 'label' => 'Test tax B', 'applies_to' => 'MERCHANDISE', 'rate_basis_points' => 250, 'taxable_amount' => $this->inr(50000), 'amount' => $this->inr(1250), 'included_in_prices' => false],
        ], $pricing['taxes']);
        $this->assertSame($this->inr(2500), $pricing['tax_total']);
        $this->assertSame($this->inr(0), $pricing['tax_included_total']);
        $this->assertSame($this->inr(52500), $pricing['total']);
        $this->assertSame('TEST', $pricing['configuration']['tax'], 'a development rule is reported as a TEST configuration');
    }

    public function test_tax_that_is_already_inside_the_prices_is_reported_and_not_added(): void
    {
        // 5 % in two halves, INCLUDED in the displayed prices: of ₹500.00 the tax part is 500 − 500/1.05 = ₹23.81.
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 250], ['TEST_TAX_B', 'Test tax B', 250]], ['price_treatment' => 'INCLUSIVE']);

        $pricing = $this->pricingOf();

        $this->assertSame($this->inr(50000), $pricing['total'], 'nothing is added on top');
        $this->assertSame($this->inr(0), $pricing['tax_total']);
        $this->assertSame($this->inr(2381), $pricing['tax_included_total']);
        // The parts add up exactly to the whole (largest remainder: 1191 + 1190).
        $this->assertSame([1191, 1190], array_map(fn (array $t): int => $t['amount']['amount'], $pricing['taxes']));
        $this->assertTrue($pricing['taxes'][0]['included_in_prices']);
    }

    public function test_the_rule_decides_whether_the_discount_comes_off_before_the_tax(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        $after = $this->taxRule([['TEST_TAX_A', 'Test tax A', 1000]]);

        // AFTER_DISCOUNT: 10 % of (₹500 − ₹100) = ₹40.
        $pricing = $this->pricingOf(2, ['Size' => ['Large']], ['promotion_code' => 'SAVE100']);
        $this->assertSame($this->inr(40000), $pricing['taxes'][0]['taxable_amount']);
        $this->assertSame($this->inr(4000), $pricing['tax_total']);
        $this->assertSame($this->inr(44000), $pricing['total']);

        // BEFORE_DISCOUNT: 10 % of ₹500 = ₹50.
        $after->forceFill(['discount_treatment' => 'BEFORE_DISCOUNT', 'version' => 2])->save();
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $before = $this->openCheckout($headers, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout.pricing');
        $this->assertSame($this->inr(50000), $before['taxes'][0]['taxable_amount']);
        $this->assertSame($this->inr(5000), $before['tax_total']);
        $this->assertSame($this->inr(45000), $before['total']);
    }

    public function test_tax_rules_follow_their_effective_dates_their_status_and_the_most_specific_scope(): void
    {
        // Not yet in force, ended, a draft, and disabled: none of them applies.
        $this->taxRule([['FUTURE', 'Future', 900]], ['effective_from' => now()->addDay()]);
        $this->taxRule([['ENDED', 'Ended', 900]], ['effective_from' => now()->subDays(10), 'effective_until' => now()->subDay()]);
        $this->taxRule([['DRAFT', 'Draft', 900]], ['status' => 'DRAFT']);
        $this->taxRule([['OFF', 'Off', 900]], ['status' => 'DISABLED']);
        $this->assertSame([], $this->pricingOf()['taxes']);

        // The market's rule applies — until the restaurant has its own.
        $this->taxRule([['MARKET', 'Market tax', 500]]);
        [, $second] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $this->assertSame(['MARKET'], array_column($this->openCheckout($second)->json('checkout.pricing.taxes'), 'code'));

        $this->taxRule([['LOCAL', 'Restaurant tax', 300]], ['location_id' => $this->burgerHub->getKey()]);
        [, $third] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543212');
        $local = $this->openCheckout($third)->json('checkout.pricing');
        $this->assertSame(['LOCAL'], array_column($local['taxes'], 'code'));
        $this->assertSame($this->inr(1500), $local['tax_total']);
    }

    public function test_test_rules_are_not_applied_where_test_rules_are_switched_off(): void
    {
        $this->taxRule([['TEST_TAX_A', 'Test tax A', 500]]);
        $this->feeRule();
        config(['checkout.financial_rules.allow_test' => false]);

        // As in production: a TEST configuration reaches no customer.
        $pricing = $this->pricingOf();
        $this->assertSame([], $pricing['taxes']);
        $this->assertSame([], $pricing['fees']);
        $this->assertSame($this->inr(50000), $pricing['total']);

        // An APPROVED rule does.
        $this->taxRule([['APPROVED_TAX', 'Approved tax', 500]], ['approval' => 'APPROVED']);
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543211');
        $approved = $this->openCheckout($headers)->json('checkout.pricing');
        $this->assertSame(['APPROVED_TAX'], array_column($approved['taxes'], 'code'));
        $this->assertSame('APPROVED', $approved['configuration']['tax']);
    }

    public function test_a_configured_fixed_fee_is_added_and_a_promotion_does_not_reduce_it(): void
    {
        $this->feeRule(['code' => 'PLATFORM_FEE', 'label' => 'Test platform fee', 'amount_minor' => 1500]);
        $this->promotion(['code' => 'SAVE100']);

        $pricing = $this->pricingOf(2, ['Size' => ['Large']], ['promotion_code' => 'SAVE100']);

        $this->assertSame([['code' => 'PLATFORM_FEE', 'label' => 'Test platform fee', 'amount' => $this->inr(1500), 'taxable' => false]], $pricing['fees']);
        $this->assertSame($this->inr(1500), $pricing['fee_total']);
        $this->assertSame($this->inr(50000 - 10000 + 1500), $pricing['total']);
        $this->assertSame('TEST', $pricing['configuration']['fees']);
    }

    public function test_a_percentage_fee_respects_its_base_its_minimum_and_its_maximum(): void
    {
        // 2 % of the subtotal (before the discount), at least ₹5 and at most ₹8: 2 % of ₹500 = ₹10 → ₹8.
        $this->feeRule(['code' => 'SERVICE_FEE', 'label' => 'Test service fee', 'type' => 'PERCENTAGE', 'percent_basis_points' => 200, 'minimum_minor' => 500, 'maximum_minor' => 800]);
        $this->assertSame($this->inr(800), $this->pricingOf()['fee_total']);

        // 1 % of ₹200 = ₹2 → the minimum ₹5.
        [, $small] = $this->customerReadyForCheckout(1, ['Size' => ['Regular']], null, '+919876543211');
        FeeRule::query()->update(['percent_basis_points' => 100]);
        $this->assertSame($this->inr(500), $this->openCheckout($small)->json('checkout.pricing.fee_total'));

        // Of the DISCOUNTED subtotal when the rule says so: 1 % of (₹500 − ₹100) = ₹4 → minimum ₹5; without bounds ₹4.
        FeeRule::query()->update(['percent_of' => 'DISCOUNTED_SUBTOTAL', 'minimum_minor' => null, 'maximum_minor' => null]);
        $this->promotion(['code' => 'SAVE100']);
        [, $third] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+919876543212');
        $this->assertSame($this->inr(400), $this->openCheckout($third, ['promotion_code' => 'SAVE100'])->json('checkout.pricing.fee_total'));
    }

    public function test_a_taxable_fee_is_taxed_by_the_fees_rule_and_an_untaxable_one_is_not(): void
    {
        $this->feeRule(['code' => 'SERVICE_FEE', 'label' => 'Test service fee', 'amount_minor' => 2000, 'taxable' => true]);
        $this->feeRule(['code' => 'PLATFORM_FEE', 'label' => 'Test platform fee', 'amount_minor' => 1000, 'taxable' => false]);
        $this->taxRule([['TEST_FEE_TAX', 'Test tax on fees', 1800]], ['applies_to' => 'FEES', 'name' => 'TEST FEE TAX CONFIGURATION']);

        $pricing = $this->pricingOf();

        // 18 % of the ₹20 taxable fee = ₹3.60; the merchandise has no rule, so it carries no tax.
        $this->assertSame([['code' => 'TEST_FEE_TAX', 'label' => 'Test tax on fees', 'applies_to' => 'FEES', 'rate_basis_points' => 1800, 'taxable_amount' => $this->inr(2000), 'amount' => $this->inr(360), 'included_in_prices' => false]], $pricing['taxes']);
        $this->assertSame($this->inr(3000), $pricing['fee_total']);
        $this->assertSame($this->inr(50000 + 3000 + 360), $pricing['total']);
    }

    public function test_fees_follow_the_pickup_method_and_the_restaurant_they_are_configured_for(): void
    {
        $this->feeRule(['code' => 'PLATFORM_FEE', 'amount_minor' => 1500]);
        // A curbside-only fee does not apply to a counter pickup; another restaurant's fee does not apply here.
        $this->feeRule(['code' => 'SERVICE_FEE', 'label' => 'Curbside service', 'amount_minor' => 2500, 'pickup_method' => 'CURBSIDE']);
        [$other] = $this->otherRestaurant();
        $this->feeRule(['code' => 'PACKING_FEE', 'label' => 'Other restaurant', 'amount_minor' => 900, 'location_id' => $other->getKey()]);
        // This restaurant's own platform fee replaces the market's.
        $this->feeRule(['code' => 'PLATFORM_FEE', 'label' => 'Burger Hub platform fee', 'amount_minor' => 700, 'location_id' => $this->burgerHub->getKey()]);

        $pricing = $this->pricingOf();

        $this->assertSame([['code' => 'PLATFORM_FEE', 'label' => 'Burger Hub platform fee', 'amount' => $this->inr(700), 'taxable' => false]], $pricing['fees']);
    }

    public function test_a_promotion_that_takes_off_everything_still_leaves_a_configured_fee_to_pay(): void
    {
        // A promotion reduces the merchandise. It reduces a fee only when the fee is configured to follow the discount.
        $this->promotion(['code' => 'FREEMEAL', 'type' => 'PERCENTAGE', 'percent_basis_points' => 10000]);
        $this->feeRule();                                                                                                   // fixed ₹15
        $this->feeRule(['code' => 'PACKAGING', 'label' => 'Test packaging charge', 'type' => 'PERCENTAGE', 'percent_basis_points' => 200]);                       // 2 % of the subtotal
        $this->feeRule(['code' => 'SERVICE', 'label' => 'Test service charge', 'type' => 'PERCENTAGE', 'percent_basis_points' => 200, 'percent_of' => 'DISCOUNTED_SUBTOTAL']);   // 2 % of what is left
        [, $headers] = $this->customerReadyForCheckout();   // ₹500

        $pricing = $this->openCheckout($headers, ['promotion_code' => 'FREEMEAL'])->assertCreated()->json('checkout.pricing');

        $this->assertSame([$this->inr(50000), $this->inr(50000)], [$pricing['items_subtotal'], $pricing['discount_total']]);
        // (The service charge is 2 % of nothing: a fee of zero is not a line.)
        $this->assertSame(['PACKAGING' => 1000, 'PLATFORM_FEE' => 1500], collect($pricing['fees'])->mapWithKeys(fn (array $f): array => [$f['code'] => $f['amount']['amount']])->sortKeys()->all());
        // ₹0 of merchandise, ₹25 of fees: there is something to pay.
        $this->assertSame([$this->inr(2500), true], [$pricing['total'], $pricing['payment_required']]);
    }

    public function test_a_delivery_fee_or_a_commission_cannot_even_be_configured(): void
    {
        foreach (['DELIVERY_FEE', 'HOME_DELIVERY', 'COMMISSION', 'RESTAURANT_COMMISSION'] as $code) {
            try {
                // (In a savepoint: a refused statement must not end the transaction the test runs in.)
                DB::transaction(fn () => $this->feeRule(['code' => $code]));
                $this->fail("A fee rule with the code {$code} was stored.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('fee_rules_code_check', $e->getMessage());
            }
        }
    }

    public function test_a_discount_never_makes_the_total_negative_and_a_total_of_zero_needs_no_payment(): void
    {
        // ₹1,000 off a ₹500 cart: the discount is the subtotal, not more.
        $this->promotion(['code' => 'HUGE', 'amount_minor' => 100000]);
        [, $headers] = $this->customerReadyForCheckout();

        $draft = $this->openCheckout($headers, ['promotion_code' => 'HUGE'])->assertCreated()->json('checkout');

        $this->assertSame($this->inr(50000), $draft['pricing']['discount_total']);
        $this->assertSame($this->inr(0), $draft['pricing']['total']);
        $this->assertFalse($draft['pricing']['payment_required']);

        // Confirmed without any payment method: nothing is charged, no provider is asked for ₹0.
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $this->assertSame('READY', $ready['status']);
        $this->assertSame('PAYMENT_NOT_REQUIRED', $ready['payment']['status']);
        $this->assertSame($this->inr(0), $ready['payment']['amount']);
        $this->assertNull($ready['payment']['payment_method']);
        $this->assertNull($ready['payment_methods']['selected']);
        $session = $this->sessionModel($ready['id']);
        $this->assertFalse($session->payment_required);
        $this->assertSame(0, $session->total_minor);
    }

    public function test_a_100_percent_promotion_leaves_configured_fees_and_their_tax_to_pay(): void
    {
        $this->promotion(['code' => 'FREE', 'type' => 'PERCENTAGE', 'percent_basis_points' => 10000]);
        $this->feeRule(['amount_minor' => 1500]);

        $pricing = $this->pricingOf(2, ['Size' => ['Large']], ['promotion_code' => 'FREE']);

        $this->assertSame($this->inr(50000), $pricing['discount_total']);
        $this->assertSame($this->inr(1500), $pricing['total']);
        $this->assertTrue($pricing['payment_required']);
    }

    public function test_the_database_itself_refuses_a_total_that_does_not_add_up(): void
    {
        [, $headers] = $this->customerReadyForCheckout();
        $id = $this->openCheckout($headers)->json('checkout.id');

        foreach ([['total_minor' => 1], ['discount_minor' => 60000, 'total_minor' => 0], ['currency' => 'inr'], ['payment_required' => false]] as $tampered) {
            try {
                DB::transaction(fn () => DB::table('checkout_sessions')->where('public_id', $id)->update($tampered));
                $this->fail('The database accepted '.json_encode($tampered));
            } catch (QueryException $e) {
                $this->assertStringContainsString('checkout_sessions_', $e->getMessage());
            }
        }
    }

    public function test_the_fingerprint_changes_with_everything_the_price_follows_from(): void
    {
        [, $headers] = $this->customerReadyForCheckout();
        $plain = $this->openCheckout($headers)->json('checkout.pricing.fingerprint');
        $this->assertSame($plain, $this->openCheckout($headers)->json('checkout.pricing.fingerprint'), 'the same situation has the same fingerprint');

        $this->promotion(['code' => 'SAVE100']);
        $withPromotion = $this->openCheckout($headers, ['promotion_code' => 'SAVE100'])->json('checkout.pricing.fingerprint');
        $this->taxRule();
        $withTax = $this->openCheckout($headers)->json('checkout.pricing.fingerprint');
        $this->feeRule();
        $withFee = $this->openCheckout($headers)->json('checkout.pricing.fingerprint');

        $this->assertCount(4, array_unique([$plain, $withPromotion, $withTax, $withFee]));
    }
}
