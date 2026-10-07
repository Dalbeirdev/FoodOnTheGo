<?php

namespace Tests\Feature\Checkout;

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\FeeRule;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\Role;
use App\Models\TaxRule;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * Tax rules and fee rules of a market for platform administrators (Module 29). Nothing is configured until an
 * administrator configures it; a rule is a TEST configuration until it is marked APPROVED; an active rule is frozen.
 *
 * The rates and amounts in this class are test fixtures. None is an official rate of any country.
 */
class AdminFinancialRulesApiTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    private AdminUser $super;

    private ?RestaurantLocation $abroad = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->seed(RoleSeeder::class);
        $this->super = $this->adminWithRole('SUPER_ADMIN');
    }

    private function adminWithRole(string $code): AdminUser
    {
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail(), null);

        return $admin;
    }

    private function market(string $suffix = '/financial-rules', ?Market $market = null): string
    {
        return '/api/v1/admin/markets/'.($market ?? $this->market)->public_id.$suffix;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTax(array $overrides = []): TestResponse
    {
        $this->actingAsPrincipal($this->super);

        return $this->postJson($this->market('/tax-rules'), [
            'name' => 'TEST TAX CONFIGURATION', 'applies_to' => 'MERCHANDISE', 'price_treatment' => 'EXCLUSIVE', 'effective_from' => now()->subHour()->toIso8601String(),
            'components' => [['code' => 'test_tax_a', 'label' => 'Test tax A', 'rate_basis_points' => 250], ['code' => 'TEST_TAX_B', 'label' => 'Test tax B', 'rate_basis_points' => 250]],
            'reason' => 'Development configuration', ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createFee(array $overrides = []): TestResponse
    {
        $this->actingAsPrincipal($this->super);

        return $this->postJson($this->market('/fee-rules'), ['code' => 'PLATFORM_FEE', 'label' => 'Test platform fee', 'type' => 'FIXED', 'currency' => 'INR', 'amount_minor' => 1500, 'effective_from' => now()->subHour()->toIso8601String(), 'reason' => 'Development configuration', ...$overrides]);
    }

    /**
     * A change by the super admin (the customers created in between leave their session on the guards).
     *
     * @param  array<string, mixed>  $body
     */
    private function change(string $url, array $body): TestResponse
    {
        $this->actingAsPrincipal($this->super);

        return $this->patchJson($url, $body);
    }

    private function setStatus(string $kind, array $rule, string $status, string $reason = 'Configuration decision'): TestResponse
    {
        $this->actingAsPrincipal($this->super);

        return $this->postJson('/api/v1/admin/'.$kind.'/'.$rule['id'].'/status', ['status' => $status, 'version' => $rule['version'], 'reason' => $reason]);
    }

    /**
     * The checkout a customer gets right now (₹500 of burgers).
     *
     * @return array<string, mixed>
     */
    private function pricingNow(): array
    {
        static $n = 0;
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], null, '+9198765432'.str_pad((string) (10 + $n++ % 80), 2, '0', STR_PAD_LEFT));

        return $this->openCheckout($headers)->assertCreated()->json('checkout.pricing');
    }

    /* ------------------------------------------------------------------ nothing is invented */

    public function test_a_market_has_no_tax_and_no_fee_until_an_administrator_configures_them(): void
    {
        $this->actingAsPrincipal($this->super);
        $rules = $this->getJson($this->market())->assertOk();

        $rules->assertJsonPath('market.default_currency', 'INR')->assertJsonPath('tax_rules', [])->assertJsonPath('fee_rules', [])->assertJsonPath('configured', ['tax' => false, 'fees' => false])->assertJsonPath('test_rules_applied', true);
        $this->assertSame([0, 0], [TaxRule::query()->count(), FeeRule::query()->count()], 'no migration and no seeder puts a rate in');
        // And a customer is charged exactly the merchandise: no tax line, no fee line, and the answer says why.
        $pricing = $this->pricingNow();
        $this->assertSame([[], [], $this->inr(50000), ['tax' => 'NOT_CONFIGURED', 'fees' => 'NOT_CONFIGURED']], [$pricing['taxes'], $pricing['fees'], $pricing['total'], $pricing['configuration']]);
    }

    /* ------------------------------------------------------------------ who */

    public function test_only_administrators_with_the_market_configuration_permission_reach_the_rules(): void
    {
        $tax = $this->createTax()->assertCreated()->json('tax_rule');
        $fee = $this->createFee()->assertCreated()->json('fee_rule');
        $this->app['auth']->forgetGuards();
        $writes = function (int $status) use ($tax, $fee): void {
            $this->postJson($this->market('/tax-rules'), ['name' => 'X'])->assertStatus($status);
            $this->postJson($this->market('/fee-rules'), ['code' => 'X'])->assertStatus($status);
            $this->patchJson('/api/v1/admin/tax-rules/'.$tax['id'], ['name' => 'X', 'version' => 1, 'reason' => 'Try'])->assertStatus($status);
            $this->patchJson('/api/v1/admin/fee-rules/'.$fee['id'], ['label' => 'X', 'version' => 1, 'reason' => 'Try'])->assertStatus($status);
            $this->postJson('/api/v1/admin/tax-rules/'.$tax['id'].'/status', ['status' => 'ACTIVE', 'version' => 1, 'reason' => 'Try'])->assertStatus($status);
            $this->postJson('/api/v1/admin/fee-rules/'.$fee['id'].'/status', ['status' => 'ACTIVE', 'version' => 1, 'reason' => 'Try'])->assertStatus($status);
        };

        // Nobody, a customer, and the owner of a restaurant of this very market.
        $this->getJson($this->market())->assertStatus(401);
        $writes(401);
        [, $customer] = $this->signedIn();
        $this->getJson($this->market(), $customer)->assertStatus(401);
        $this->actingAsPrincipal($this->staff());
        $this->getJson($this->market())->assertStatus(401);
        $writes(401);
        foreach (['/api/v1/restaurant/tax-rules', '/api/v1/restaurant/fee-rules', '/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/fee-rules'] as $path) {
            $this->getJson($path)->assertNotFound();
        }

        // Seeded roles: support and moderation see nothing; finance, operations and analysts read; nobody but the super admin writes.
        foreach (['SUPPORT_ADMIN', 'MODERATION_ADMIN'] as $role) {
            $this->actingAsPrincipal($this->adminWithRole($role));
            $this->getJson($this->market())->assertForbidden();
            $writes(403);
        }
        foreach (['FINANCE_ADMIN', 'OPERATIONS_ADMIN', 'ANALYST'] as $role) {
            $this->actingAsPrincipal($this->adminWithRole($role));
            $this->getJson($this->market())->assertOk()->assertJsonCount(1, 'tax_rules')->assertJsonCount(1, 'fee_rules');
            $writes(403);
        }
        // The permission for one market is not the permission for another.
        $uae = Market::factory()->create(['country_code' => 'AE', 'slug' => 'ae', 'name' => 'United Arab Emirates', 'default_currency' => 'AED']);
        $this->actingAsPrincipal($this->adminWith([Permission::AdminMarketConfigurationView, Permission::AdminMarketConfigurationManage], $uae));
        $this->getJson($this->market())->assertForbidden();
        $writes(403);
        $this->getJson($this->market('/financial-rules', $uae))->assertOk()->assertJsonPath('tax_rules', []);

        $this->assertSame(['DRAFT', 'DRAFT', 1, 1], [TaxRule::query()->sole()->status->value, FeeRule::query()->sole()->status->value, (int) TaxRule::query()->sole()->version, (int) FeeRule::query()->sole()->version]);
    }

    /* ------------------------------------------------------------------ tax rules */

    public function test_a_tax_rule_is_created_as_a_test_draft_with_integer_rates_and_audited(): void
    {
        $rule = $this->createTax()->assertCreated()->json('tax_rule');

        $this->assertSame(['TEST TAX CONFIGURATION', 'MERCHANDISE', 'EXCLUSIVE', 'AFTER_DISCOUNT', 'DRAFT', 'TEST', false, null, null, 1], [$rule['name'], $rule['applies_to'], $rule['price_treatment'], $rule['discount_treatment'], $rule['status'], $rule['approval'], $rule['in_force'], $rule['effective_until'], $rule['location'], $rule['version']]);
        $this->assertSame([['code' => 'TEST_TAX_A', 'label' => 'Test tax A', 'rate_basis_points' => 250], ['code' => 'TEST_TAX_B', 'label' => 'Test tax B', 'rate_basis_points' => 250]], $rule['components']);
        $audit = AuditEvent::query()->where('action', 'tax_rule.created')->sole();
        $this->assertSame(['tax_rules', $rule['id'], (string) $this->super->public_id, 'Development configuration', (int) $this->market->getKey()], [$audit->target_type, $audit->target_public_id, $audit->actor_public_id, $audit->reason, (int) $audit->market_id]);
        $this->assertEquals(['from' => null, 'to' => ['TEST_TAX_A:250', 'TEST_TAX_B:250']], $audit->changes['components']);

        $refused = fn (array $overrides, string $field) => $this->createTax($overrides)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        // A rate is an integer number of basis points: 2.5 % is 250, never 2.5 and never "2.5%".
        $refused(['components' => [['code' => 'A', 'label' => 'A', 'rate_basis_points' => 2.5]]], 'components.0.rate_basis_points');
        $refused(['components' => [['code' => 'AA', 'label' => 'A', 'rate_basis_points' => '2.5%']]], 'components.0.rate_basis_points');
        $refused(['components' => [['code' => 'AA', 'label' => 'A', 'rate_basis_points' => 10001]]], 'components.0.rate_basis_points');
        $refused(['components' => [['code' => 'AA', 'label' => 'A', 'rate_basis_points' => -1]]], 'components.0.rate_basis_points');
        $refused(['components' => [['code' => 'AA', 'label' => 'A', 'rate_basis_points' => 100], ['code' => 'aa', 'label' => 'Again', 'rate_basis_points' => 100]]], 'components.0.code');
        $refused(['components' => [['code' => 'AA', 'label' => 'A', 'rate_basis_points' => 100, 'rate' => 0.05]]], 'components.0');
        $refused(['components' => []], 'components');
        $refused(['applies_to' => 'EVERYTHING'], 'applies_to');
        $refused(['price_treatment' => 'MAYBE'], 'price_treatment');
        $refused(['effective_until' => now()->subDay()->toIso8601String()], 'effective_until');
        $refused(['reason' => ''], 'reason');
        $refused(['status' => 'ACTIVE'], 'status');
        $refused(['location_id' => (string) $this->otherMarketRestaurant()->public_id], 'location_id');
        $this->assertSame(1, TaxRule::query()->count());
    }

    public function test_a_tax_rule_applies_only_while_active_and_is_frozen_from_then_on(): void
    {
        $rule = $this->createTax()->assertCreated()->json('tax_rule');
        $url = '/api/v1/admin/tax-rules/'.$rule['id'];
        $this->assertSame($this->inr(50000), $this->pricingNow()['total'], 'a draft charges nobody');

        // A draft can be rewritten completely.
        $rule = $this->change($url, ['name' => 'TEST TAX CONFIGURATION (two parts)', 'components' => [['code' => 'TEST_TAX_A', 'label' => 'Test tax A', 'rate_basis_points' => 300], ['code' => 'TEST_TAX_B', 'label' => 'Test tax B', 'rate_basis_points' => 200]], 'version' => 1, 'reason' => 'Split differently'])->assertOk()->json('tax_rule');
        $this->assertSame([2, [300, 200]], [$rule['version'], array_column($rule['components'], 'rate_basis_points')]);
        $this->assertEquals(['from' => ['TEST_TAX_A:250', 'TEST_TAX_B:250'], 'to' => ['TEST_TAX_A:300', 'TEST_TAX_B:200']], AuditEvent::query()->where('action', 'tax_rule.updated')->sole()->changes['components']);

        $rule = $this->setStatus('tax-rules', $rule, 'ACTIVE', 'Switch on for development')->assertOk()->json('tax_rule');
        $this->assertSame(['ACTIVE', true, 3], [$rule['status'], $rule['in_force'], $rule['version']]);
        // Now customers are charged it — and the checkout says it is a TEST configuration.
        $pricing = $this->pricingNow();
        $this->assertSame([$this->inr(52500), $this->inr(2500), 'TEST'], [$pricing['total'], $pricing['tax_total'], $pricing['configuration']['tax']]);
        $this->assertSame([['TEST_TAX_A', 'Test tax A', 300, $this->inr(1500)], ['TEST_TAX_B', 'Test tax B', 200, $this->inr(1000)]], array_map(fn (array $t): array => [$t['code'], $t['label'], $t['rate_basis_points'], $t['amount']], $pricing['taxes']));
        $this->actingAsPrincipal($this->super);
        $this->getJson($this->market())->assertOk()->assertJsonPath('configured.tax', false);   // a TEST rule is not "configured"

        // Frozen: neither its rates nor anything else that decides an amount can be rewritten.
        $frozen = fn (array $change) => $this->change($url, [...$change, 'version' => 3, 'reason' => 'Rewrite'])->assertConflict()->assertJsonPath('error.code', 'rule_frozen');
        $frozen(['components' => [['code' => 'TEST_TAX_A', 'label' => 'Test tax A', 'rate_basis_points' => 1]]])->assertJsonPath('error.details.field', 'components');
        $frozen(['price_treatment' => 'INCLUSIVE']);
        $frozen(['discount_treatment' => 'BEFORE_DISCOUNT']);
        $frozen(['applies_to' => 'FEES']);
        $frozen(['name' => 'Renamed']);
        $frozen(['approval' => 'APPROVED']);
        $frozen(['effective_from' => now()->subDays(30)->toIso8601String()]);
        $this->setStatus('tax-rules', $rule, 'DRAFT')->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');
        $this->assertSame($this->inr(52500), $this->pricingNow()['total']);

        // What it does allow is an end. From then on a new rule can take over; the old one stays as it was.
        $this->change($url, ['effective_until' => now()->subDay()->toIso8601String(), 'version' => 3, 'reason' => 'End it'])->assertUnprocessable()->assertJsonPath('error.details.fields.effective_until.0', 'The end must be after the start.');
        $ended = $this->change($url, ['effective_until' => now()->addHours(2)->toIso8601String(), 'version' => 3, 'reason' => 'Replaced tomorrow'])->assertOk()->json('tax_rule');
        $this->assertSame([4, true], [$ended['version'], $ended['in_force']]);
        $this->travel(3)->hours();
        $this->assertSame($this->inr(50000), $this->pricingNow()['total'], 'past its end the rule charges nobody');
        $this->actingAsPrincipal($this->super);
        $this->getJson($this->market())->assertJsonPath('tax_rules.0.in_force', false)->assertJsonPath('tax_rules.0.status', 'ACTIVE');

        // Disabled is final.
        $disabled = $this->setStatus('tax-rules', $ended, 'DISABLED', 'No longer needed')->assertOk()->json('tax_rule');
        $this->setStatus('tax-rules', $disabled, 'ACTIVE')->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');
        $this->change($url, ['effective_until' => null, 'version' => $disabled['version'], 'reason' => 'Reopen'])->assertConflict()->assertJsonPath('error.code', 'rule_frozen');
        $this->assertSame(['tax_rule.created', 'tax_rule.updated', 'tax_rule.status_changed', 'tax_rule.updated', 'tax_rule.status_changed'], AuditEvent::query()->where('action', 'like', 'tax_rule.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_an_approved_rule_is_what_makes_a_market_configured_and_test_rules_can_be_switched_off(): void
    {
        $test = $this->setStatus('tax-rules', $this->createTax()->json('tax_rule'), 'ACTIVE')->assertOk()->json('tax_rule');
        $this->assertSame('TEST', $this->pricingNow()['configuration']['tax']);

        // Where test rules are not allowed (production), a TEST rule charges nobody — whatever its status says.
        config(['checkout.financial_rules.allow_test' => false]);
        $pricing = $this->pricingNow();
        $this->assertSame([$this->inr(50000), [], 'NOT_CONFIGURED'], [$pricing['total'], $pricing['taxes'], $pricing['configuration']['tax']]);
        $this->actingAsPrincipal($this->super);
        $this->getJson($this->market())->assertJsonPath('test_rules_applied', false)->assertJsonPath('configured.tax', false);

        // A rule an administrator marked APPROVED applies there. (The rate here is still only a test fixture.)
        $this->setStatus('tax-rules', $test, 'DISABLED')->assertOk();
        $approved = $this->createTax(['name' => 'Approved rule (fixture)', 'approval' => 'APPROVED', 'components' => [['code' => 'FIXTURE_TAX', 'label' => 'Fixture tax', 'rate_basis_points' => 400]]])->assertCreated()->json('tax_rule');
        $this->setStatus('tax-rules', $approved, 'ACTIVE')->assertOk()->assertJsonPath('tax_rule.approval', 'APPROVED');
        $pricing = $this->pricingNow();
        $this->assertSame([$this->inr(52000), 'APPROVED'], [$pricing['total'], $pricing['configuration']['tax']]);
        $this->actingAsPrincipal($this->super);
        $this->getJson($this->market())->assertJsonPath('configured.tax', true)->assertJsonPath('configured.fees', false);
    }

    /* ------------------------------------------------------------------ fee rules */

    public function test_a_fee_rule_is_created_as_a_test_draft_and_only_coherent_rules_are_stored(): void
    {
        $fee = $this->createFee()->assertCreated()->json('fee_rule');

        $this->assertSame(['PLATFORM_FEE', 'Test platform fee', 'FIXED', 'INR', 1500, null, 'SUBTOTAL', null, false, 'DRAFT', 'TEST', false, 1], [$fee['code'], $fee['label'], $fee['type'], $fee['currency'], $fee['amount_minor'], $fee['percent_basis_points'], $fee['percent_of'], $fee['pickup_method'], $fee['taxable'], $fee['status'], $fee['approval'], $fee['in_force'], $fee['version']]);
        $audit = AuditEvent::query()->where('action', 'fee_rule.created')->sole();
        $this->assertSame(['fee_rules', $fee['id'], 'Development configuration'], [$audit->target_type, $audit->target_public_id, $audit->reason]);
        $this->assertEquals(['from' => null, 'to' => 'PLATFORM_FEE'], $audit->changes['fee_code']);

        $refused = fn (array $overrides, string $field) => $this->createFee(['code' => 'OTHER_FEE', ...$overrides])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        // A pickup product has no delivery fee, and a commission is never a customer fee: not under any name.
        foreach (['DELIVERY_FEE', 'HOME_DELIVERY', 'DELIVERY', 'EXPRESS_DELIVERY_CHARGE'] as $code) {
            $refused(['code' => $code], 'code')->assertJsonPath('error.details.fields.code.0', 'FoodOnTheGo is a pickup product: there is no delivery fee.');
        }
        foreach (['COMMISSION', 'RESTAURANT_COMMISSION'] as $code) {
            $refused(['code' => $code], 'code')->assertJsonPath('error.details.fields.code.0', 'A restaurant commission is a settlement matter, never a customer fee.');
        }
        $refused(['code' => 'platform fee'], 'code');
        $refused(['code' => 'delivery_fee'], 'code');
        // Integers in the market's currency; the parts must fit the type.
        $refused(['amount_minor' => 15.5], 'amount_minor');
        $refused(['amount_minor' => 0], 'amount_minor');
        $refused(['amount_minor' => null], 'amount_minor');
        $refused(['currency' => 'USD'], 'currency');
        $refused(['currency' => null], 'amount_minor');
        $refused(['percent_basis_points' => 200], 'amount_minor');
        $refused(['type' => 'PERCENTAGE'], 'percent_basis_points');
        $refused(['type' => 'PERCENTAGE', 'amount_minor' => null, 'percent_basis_points' => 2.5], 'percent_basis_points');
        $refused(['type' => 'PERCENTAGE', 'amount_minor' => null, 'currency' => null, 'percent_basis_points' => 200, 'maximum_minor' => 3000], 'currency');
        $refused(['minimum_minor' => 5000, 'maximum_minor' => 1000], 'maximum_minor');
        $refused(['type' => 'PER_KILOMETRE'], 'type');
        $refused(['pickup_method' => 'DELIVERY'], 'pickup_method');
        $refused(['effective_until' => now()->subDay()->toIso8601String()], 'effective_until');
        $refused(['status' => 'ACTIVE'], 'status');
        $refused(['location_id' => (string) $this->otherMarketRestaurant()->public_id], 'location_id');
        $this->assertSame(1, FeeRule::query()->count());

        // A percentage fee with bounds, for one restaurant and one way of picking up: all of it is configuration.
        $percent = $this->createFee(['code' => 'PACKAGING', 'label' => 'Test packaging charge', 'type' => 'PERCENTAGE', 'amount_minor' => null, 'percent_basis_points' => 200, 'percent_of' => 'DISCOUNTED_SUBTOTAL', 'minimum_minor' => 500, 'maximum_minor' => 3000, 'pickup_method' => 'COUNTER', 'taxable' => true, 'location_id' => (string) $this->burgerHub->public_id])->assertCreated()->json('fee_rule');
        $this->assertSame(['PERCENTAGE', 200, null, 'DISCOUNTED_SUBTOTAL', 500, 3000, 'COUNTER', true, (string) $this->burgerHub->public_id], [$percent['type'], $percent['percent_basis_points'], $percent['amount_minor'], $percent['percent_of'], $percent['minimum_minor'], $percent['maximum_minor'], $percent['pickup_method'], $percent['taxable'], $percent['location']['id']]);
    }

    public function test_a_fee_rule_applies_only_while_active_and_is_frozen_from_then_on(): void
    {
        $fee = $this->createFee()->assertCreated()->json('fee_rule');
        $url = '/api/v1/admin/fee-rules/'.$fee['id'];
        $this->assertSame($this->inr(50000), $this->pricingNow()['total'], 'a draft charges nobody');

        // A draft can be rewritten — but never into something that does not fit together, or into a delivery fee.
        $this->change($url, ['percent_basis_points' => 200, 'version' => 1, 'reason' => 'Add a rate'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['amount_minor']]]]);
        $this->change($url, ['code' => 'DELIVERY_FEE', 'version' => 1, 'reason' => 'Rename'])->assertUnprocessable()->assertJsonPath('error.details.fields.code.0', 'FoodOnTheGo is a pickup product: there is no delivery fee.');
        $this->change($url, ['amount_minor' => 2000, 'version' => 5, 'reason' => 'Raise'])->assertConflict()->assertJsonPath('error.code', 'stale_update');
        $fee = $this->change($url, ['amount_minor' => 2000, 'label' => 'Test service fee', 'version' => 1, 'reason' => 'Raise'])->assertOk()->json('fee_rule');
        $this->assertSame([2000, 'Test service fee', 2], [$fee['amount_minor'], $fee['label'], $fee['version']]);
        $this->assertEquals(['from' => 1500, 'to' => 2000], AuditEvent::query()->where('action', 'fee_rule.updated')->sole()->changes['amount_minor']);

        $fee = $this->setStatus('fee-rules', $fee, 'ACTIVE')->assertOk()->json('fee_rule');
        $pricing = $this->pricingNow();
        $this->assertSame([$this->inr(52000), $this->inr(2000), 'TEST'], [$pricing['total'], $pricing['fee_total'], $pricing['configuration']['fees']]);
        $this->assertSame([['code' => 'PLATFORM_FEE', 'label' => 'Test service fee', 'amount' => $this->inr(2000), 'taxable' => false]], $pricing['fees']);

        $frozen = fn (array $change) => $this->change($url, [...$change, 'version' => 3, 'reason' => 'Rewrite'])->assertConflict()->assertJsonPath('error.code', 'rule_frozen');
        $frozen(['amount_minor' => 1])->assertJsonPath('error.details.field', 'amount_minor');
        $frozen(['label' => 'Another name']);
        $frozen(['taxable' => true]);
        $frozen(['type' => 'PERCENTAGE', 'amount_minor' => null, 'percent_basis_points' => 100]);
        $frozen(['approval' => 'APPROVED']);
        $frozen(['location_id' => (string) $this->burgerHub->public_id]);
        $this->assertSame($this->inr(52000), $this->pricingNow()['total']);

        // Ended, then disabled.
        $ended = $this->change($url, ['effective_until' => now()->addHour()->toIso8601String(), 'version' => 3, 'reason' => 'End it'])->assertOk()->json('fee_rule');
        $this->travel(61)->minutes();
        $this->assertSame($this->inr(50000), $this->pricingNow()['total']);
        $disabled = $this->setStatus('fee-rules', $ended, 'DISABLED')->assertOk()->json('fee_rule');
        $this->setStatus('fee-rules', $disabled, 'ACTIVE')->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');
        $this->assertSame(['fee_rule.created', 'fee_rule.updated', 'fee_rule.status_changed', 'fee_rule.updated', 'fee_rule.status_changed'], AuditEvent::query()->where('action', 'like', 'fee_rule.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_rule_change_reaches_a_confirmed_checkout_before_it_can_be_paid(): void
    {
        [, $asha] = $this->customerReadyForCheckout();
        $ready = $this->confirmCheckout($asha, $this->openCheckout($asha)->assertCreated()->json('checkout'))->assertOk()->json('checkout');
        $this->assertSame($this->inr(50000), $ready['payment']['amount']);

        // An administrator switches a fee on while the customer is about to pay.
        $this->setStatus('fee-rules', $this->createFee()->json('fee_rule'), 'ACTIVE')->assertOk();

        $stale = $this->showCheckout($asha, $ready['id'])->assertOk()->json('checkout');
        $this->assertSame(['STALE', 'FEE_CONFIGURATION_CHANGED', null], [$stale['status'], $stale['issues'][0]['code'], $stale['payment']]);
        $this->assertEquals(['previous_total' => $this->inr(50000), 'total' => $this->inr(51500)], $stale['issues'][0]['details']);
        $next = $this->openCheckout($asha)->assertCreated()->json('checkout');
        $this->assertSame($this->inr(51500), $next['pricing']['total']);

        // The same with a tax rule.
        $ready = $this->confirmCheckout($asha, $next)->assertOk()->json('checkout');
        $this->setStatus('tax-rules', $this->createTax()->json('tax_rule'), 'ACTIVE')->assertOk();
        $this->confirmCheckout($asha, $ready)->assertConflict()->assertJsonPath('error.code', 'checkout_stale')->assertJsonPath('error.details.reason', 'TAX_CONFIGURATION_CHANGED');
        // ₹500 + ₹15 fee + 5 % test tax on ₹500 (the fee is not taxable) = ₹540.
        $this->assertSame($this->inr(54000), $this->openCheckout($asha)->assertCreated()->json('checkout.pricing.total'));
    }

    /* ------------------------------------------------------------------ numbers */

    public function test_checkout_numbers_are_counts_only_and_need_the_system_permission(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        [, $asha] = $this->customerReadyForCheckout();
        $draft = $this->openCheckout($asha, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout');
        $this->postJson('/api/v1/checkouts/'.$draft['id'].'/promotion', ['code' => 'NOSUCH'], $this->using($asha))->assertUnprocessable();
        $ready = $this->confirmCheckout($asha, $draft, ['order_note' => 'Gate 4'])->assertOk()->json('checkout');
        [, $ravi] = $this->customerReadyForCheckout(1, ['Size' => ['Regular']], null, '+919876543211');
        $this->postJson('/api/v1/checkouts/'.$this->openCheckout($ravi)->json('checkout.id').'/cancel', [], $this->using($ravi))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/system/checkout')->assertStatus(401);
        $this->getJson('/api/v1/admin/system/checkout', $this->using($asha))->assertStatus(401);
        $this->actingAsPrincipal($this->adminWith([Permission::AdminPromotionsManage]));
        $this->getJson('/api/v1/admin/system/checkout')->assertForbidden();

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $numbers = $this->getJson('/api/v1/admin/system/checkout')->assertOk();
        $numbers->assertJsonPath('checkouts.ready', 1)->assertJsonPath('checkouts.cancelled', 1)->assertJsonPath('checkouts.draft', 0)
            ->assertJsonPath('events.created', 2)->assertJsonPath('events.confirmed', 1)->assertJsonPath('events.cancelled', 1)->assertJsonPath('events.promotion_applied', 1)->assertJsonPath('events.promotion_refused', 1)
            ->assertJsonPath('rates.ready', 0.5)->assertJsonPath('rates.promotion_success', 0.5)->assertJsonPath('average_discount_minor', 10000)->assertJsonPath('active_pickup_holds', 1)
            ->assertJsonPath('promotions.active', 1)->assertJsonPath('slot_hold', 'IMPLEMENTED')->assertJsonPath('payment_provider', 'NOT_INTEGRATED');
        foreach (['Classic Burger', 'Burger Hub', 'Gate 4', 'SAVE100', 'NOSUCH', '+91', $ready['id'], $draft['pricing']['fingerprint'], 'customer_id'] as $private) {
            $this->assertStringNotContainsString($private, $numbers->getContent());
        }
    }

    /**
     * A restaurant that belongs to another market.
     */
    private function otherMarketRestaurant(): RestaurantLocation
    {
        if ($this->abroad === null) {
            $qatar = Market::factory()->create(['country_code' => 'QA', 'slug' => 'qa', 'name' => 'Qatar', 'default_currency' => 'QAR']);
            [$this->abroad] = $this->otherRestaurant('Doha Diner', 25.28, 51.52);
            $this->abroad->forceFill(['market_id' => $qatar->getKey()])->save();
        }

        return $this->abroad;
    }
}
