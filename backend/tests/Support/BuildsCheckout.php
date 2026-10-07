<?php

namespace Tests\Support;

use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\FeeRule;
use App\Models\Market;
use App\Models\MarketConfiguration;
use App\Models\Promotion;
use App\Models\TaxRule;
use App\Models\TaxRuleComponent;
use Illuminate\Testing\TestResponse;

/**
 * The world a checkout test runs in (Module 29): Burger Hub (INR, Asia/Kolkata) with a Classic Burger of ₹200
 * (Large +₹50, Extra Cheese +₹20) and Masala Fries of ₹120, on a FROZEN server clock — 2026-10-07 12:00 local unless
 * a test says otherwise. No tax rule, no fee rule and no promotion exists unless a test creates one; the rules and
 * rates tests create are test fixtures and are named as such — none is an official rate of any country.
 */
trait BuildsCheckout
{
    use BuildsPickup;

    protected function setUpCheckoutWorld(string $now = '2026-10-07 12:00'): void
    {
        $this->setUpPickupWorld($now);
        $this->marketConfiguration();
    }

    /**
     * The market's payment and legal configuration, as the India seed has it: four planned method types, cash at
     * pickup NOT APPROVED, every legal text a draft.
     *
     * @param  array<string, mixed>  $overrides  e.g. ['payment' => [...]]
     */
    protected function marketConfiguration(array $overrides = [], ?Market $market = null): MarketConfiguration
    {
        $market ??= $this->market;
        $configuration = MarketConfiguration::query()->where('market_id', $market->getKey())->first() ?? (new MarketConfiguration)->forceFill(['market_id' => $market->getKey()]);
        $configuration->forceFill([
            'payment' => ['methods' => ['upi' => 'PLANNED', 'card' => 'PLANNED', 'netbanking' => 'PLANNED', 'wallet' => 'PLANNED', 'cash_at_pickup' => 'NOT_APPROVED']],
            'legal' => ['documents' => ['terms' => 'DRAFT_PENDING_APPROVAL', 'privacy' => 'DRAFT_PENDING_APPROVAL', 'refund' => 'DRAFT_PENDING_APPROVAL', 'cookie' => 'DRAFT_PENDING_APPROVAL']],
            ...$overrides,
        ])->save();

        return $configuration;
    }

    /**
     * A signed-in customer with burgers in the cart and a pickup time chosen (ASAP unless a slot is given).
     * Two Large burgers = ₹500.
     *
     * @param  array<string, list<string>>  $chosen
     * @return array{0: Customer, 1: array<string, string>}
     */
    protected function customerReadyForCheckout(int $quantity = 2, array $chosen = ['Size' => ['Large']], ?string $slot = null, string $phone = '+919876543210'): array
    {
        [$customer, $headers] = $this->signedIn($phone);
        $this->addBurger($headers, $quantity, $chosen)->assertCreated();
        ($slot === null ? $this->selectAsap($headers) : $this->selectSlot($headers, $slot))->assertOk();

        return [$customer, $headers];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function openCheckout(array $headers, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/checkouts', $body, $this->using($headers));
    }

    protected function showCheckout(array $headers, string $id): TestResponse
    {
        return $this->getJson('/api/v1/checkouts/'.$id, $this->using($headers));
    }

    /**
     * Confirms a checkout the way a client does: with the price it was shown, the documents it listed and a way to pay.
     *
     * @param  array<string, mixed>  $checkout  the checkout as last received
     * @param  array<string, mixed>  $overrides
     */
    protected function confirmCheckout(array $headers, array $checkout, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/confirm', [
            'pricing_fingerprint' => $checkout['pricing']['fingerprint'],
            'accepted_documents' => array_map(fn (array $d): array => ['type' => $d['type'], 'version' => $d['version']], $checkout['legal']['required']),
            ...($checkout['pricing']['payment_required'] ? ['payment_method' => ['type' => 'UPI']] : []),
            ...$overrides,
        ], $this->using($headers));
    }

    /**
     * A confirmed (READY) checkout for a fresh customer.
     *
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>}
     */
    protected function readyCheckout(int $quantity = 2, ?string $slot = null, string $phone = '+919876543210'): array
    {
        [$customer, $headers] = $this->customerReadyForCheckout($quantity, ['Size' => ['Large']], $slot, $phone);
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->assertJsonPath('checkout.status', 'READY')->json('checkout');

        return [$customer, $headers, $ready];
    }

    /**
     * A test promotion. Defaults: ACTIVE, platform-wide, ₹100 off in INR.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function promotion(array $attributes = []): Promotion
    {
        $promotion = new Promotion;
        $promotion->forceFill([
            'code' => 'SAVE100', 'name' => 'Test promotion', 'type' => 'FIXED_AMOUNT', 'status' => 'ACTIVE', 'scope' => 'PLATFORM',
            'currency' => 'INR', 'amount_minor' => 10000,
            ...$attributes,
        ]);
        if (($attributes['type'] ?? null) === 'PERCENTAGE') {
            $promotion->forceFill(['amount_minor' => null, 'percent_basis_points' => $attributes['percent_basis_points'] ?? 1000, 'currency' => $attributes['currency'] ?? (isset($attributes['maximum_discount_minor']) || isset($attributes['minimum_spend_minor']) ? 'INR' : null)]);
        }
        $promotion->save();

        return $promotion->refresh();
    }

    /**
     * A TEST tax rule of the test market — a fixture for the engine, not an official rate of any country.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $components  [code, label, rate in basis points]
     * @param  array<string, mixed>  $attributes
     */
    protected function taxRule(array $components = [['TEST_TAX_A', 'Test tax A', 500]], array $attributes = []): TaxRule
    {
        $rule = new TaxRule;
        $rule->forceFill([
            'market_id' => $this->market->getKey(), 'name' => 'TEST TAX CONFIGURATION', 'applies_to' => 'MERCHANDISE', 'price_treatment' => 'EXCLUSIVE', 'discount_treatment' => 'AFTER_DISCOUNT',
            'status' => 'ACTIVE', 'approval' => 'TEST', 'effective_from' => now()->subDay(),
            ...$attributes,
        ])->save();
        foreach ($components as $i => [$code, $label, $rate]) {
            (new TaxRuleComponent)->forceFill(['tax_rule_id' => $rule->getKey(), 'code' => $code, 'label' => $label, 'rate_basis_points' => $rate, 'sequence' => $i])->save();
        }

        return $rule->refresh();
    }

    /**
     * A TEST fee rule of the test market. Defaults: a fixed ₹15 "Test platform fee".
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function feeRule(array $attributes = []): FeeRule
    {
        $rule = new FeeRule;
        $rule->forceFill([
            'market_id' => $this->market->getKey(), 'code' => 'PLATFORM_FEE', 'label' => 'Test platform fee', 'type' => 'FIXED', 'currency' => 'INR', 'amount_minor' => 1500,
            'status' => 'ACTIVE', 'approval' => 'TEST', 'effective_from' => now()->subDay(),
            ...$attributes,
        ]);
        if (($attributes['type'] ?? null) === 'PERCENTAGE') {
            $rule->forceFill(['amount_minor' => null, 'currency' => $attributes['currency'] ?? (isset($attributes['minimum_minor']) || isset($attributes['maximum_minor']) ? 'INR' : null)]);
        }
        $rule->save();

        return $rule->refresh();
    }

    protected function sessionModel(string $id): CheckoutSession
    {
        return CheckoutSession::query()->where('public_id', $id)->firstOrFail();
    }

    /**
     * @return array{amount: int, currency: string}
     */
    protected function inr(int $minor): array
    {
        return ['amount' => $minor, 'currency' => 'INR'];
    }
}
