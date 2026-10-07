<?php

namespace Tests\Feature\Checkout;

use App\Models\Customer;
use App\Models\FeeRule;
use App\Models\Market;
use App\Models\Promotion;
use App\Models\TaxRule;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalCheckoutFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * The development checkout fixture (Module 29): promotions in every state for trying checkout by hand — and no
 * tax rule and no fee rule, because a seeded rate would be an invented rate.
 */
class CheckoutFixtureTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    public function test_the_seeded_database_has_promotions_in_every_state_and_not_one_tax_or_fee_rule(): void
    {
        // Wednesday 12:00 in India.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);
        // This test tries every fixture code in one go — more than a customer may try in a minute.
        config(['rate_limits.promotion_apply' => 100]);

        $this->assertEqualsCanonicalizing(LocalCheckoutFixtureSeeder::CODES, Promotion::query()->pluck('code')->all());
        $states = Promotion::query()->get()->mapWithKeys(fn (Promotion $p): array => [$p->code => $p->effectiveStatus()->value])->all();
        $this->assertSame(['ACTIVE', 'EXPIRED', 'SCHEDULED', 'PAUSED', 'DRAFT', 'DISABLED'], [$states['WELCOME10'], $states['EXPIRED'], $states['COMINGSOON'], $states['PAUSED25'], $states['DRAFT75'], $states['OLDCODE']]);
        // Nothing about tax or fees is seeded — for India or for any market.
        $this->assertSame([0, 0], [TaxRule::query()->count(), FeeRule::query()->count()]);
        $india = Market::query()->where('country_code', 'IN')->firstOrFail();
        $this->assertSame('PENDING', $india->configuration->tax['status'] ?? null, 'India\'s tax configuration is still waiting for approved rules');

        // Asha's fixture cart at Pizza Point: ₹1,067.00 of merchandise.
        $asha = $this->sessionOf(Customer::query()->where('phone_e164', '+919876543212')->firstOrFail());
        $this->selectSlot($asha, '13:30')->assertOk();
        $checkout = $this->openCheckout($asha)->assertCreated()->json('checkout');
        $this->assertSame([$this->inr(106700), $this->inr(106700), [], [], ['tax' => 'NOT_CONFIGURED', 'fees' => 'NOT_CONFIGURED'], 'pizza-point'], [
            $checkout['pricing']['items_subtotal'], $checkout['pricing']['total'], $checkout['pricing']['taxes'], $checkout['pricing']['fees'], $checkout['pricing']['configuration'], $checkout['restaurant']['slug'],
        ]);
        // The market's method types are offered as PLANNED; cash at pickup is not.
        $this->assertSame(['UPI', 'CARD', 'NET_BANKING', 'WALLET'], array_column($checkout['payment_methods']['available'], 'type'));
        $this->assertSame(['NOT_APPROVED', 'NOT_INTEGRATED', false], [$checkout['payment_methods']['cash_at_pickup'], $checkout['payment_methods']['provider_status'], $checkout['legal']['final_approval']]);

        $apply = fn (string $code) => $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/promotion', ['code' => $code], $this->using($asha));
        $total = fn (string $code): int => $apply($code)->assertOk()->json('checkout.pricing.total.amount');
        // What applies, and what it takes off ₹1,067.00.
        $this->assertSame(96030, $total('WELCOME10'));    // 10 %
        $this->assertSame(101365, $total('travel5'));     // 5 %, codes are not case-sensitive
        $this->assertSame(101700, $total('INDIA50'));     // ₹50
        $this->assertSame(86700, $total('PIZZA20'));      // 20 % would be ₹213.40 — at most ₹200
        $this->assertSame(90695, $total('NEWBIE'));       // 15 %
        // What does not, and how the customer is told.
        $refused = fn (string $code): string => $apply($code)->assertUnprocessable()->json('error.code');
        $this->assertSame('promotion_not_applicable', $refused('BURGER20'));      // another restaurant
        $this->assertSame('promotion_minimum_not_met', $refused('BIG250'));       // ₹1,500 minimum
        $this->assertSame('promotion_expired', $refused('EXPIRED'));
        $this->assertSame('promotion_limit_reached', $refused('SOLDOUT'));
        foreach (['COMINGSOON', 'PAUSED25', 'DRAFT75', 'OLDCODE', 'NOSUCHCODE'] as $code) {
            $this->assertSame('promotion_not_found', $refused($code), $code);
        }
        // A refused code leaves the last applied one in place.
        $this->showCheckout($asha, $checkout['id'])->assertJsonPath('checkout.promotion.code', 'NEWBIE');

        // A total of zero: confirmed without a way to pay, nothing to charge.
        $free = $apply('FREEMEAL')->assertOk()->json('checkout');
        $this->assertSame([$this->inr(0), false], [$free['pricing']['total'], $free['pricing']['payment_required']]);
        $this->confirmCheckout($asha, $free)->assertOk()->assertJsonPath('checkout.payment.status', 'PAYMENT_NOT_REQUIRED');
    }

    public function test_seeding_again_restores_the_fixture_promotions_and_never_duplicates_them(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Kolkata'));
        $this->seed(DatabaseSeeder::class);
        $ids = Promotion::query()->orderBy('code')->pluck('public_id', 'code')->all();
        Promotion::query()->where('code', 'WELCOME10')->update(['status' => 'DISABLED', 'percent_basis_points' => 9900]);
        Promotion::query()->where('code', 'SOLDOUT')->update(['redeemed_count' => 0]);

        $this->seed(LocalCheckoutFixtureSeeder::class);
        $this->seed(LocalCheckoutFixtureSeeder::class);

        $this->assertSame($ids, Promotion::query()->orderBy('code')->pluck('public_id', 'code')->all());
        $welcome = Promotion::query()->where('code', 'WELCOME10')->sole();
        $this->assertSame(['ACTIVE', 1000, 1], [$welcome->status->value, $welcome->percent_basis_points, (int) Promotion::query()->where('code', 'SOLDOUT')->value('redeemed_count')]);
        $this->assertSame([0, 0], [TaxRule::query()->count(), FeeRule::query()->count()]);
    }

    public function test_test_rules_are_never_applied_in_production_whatever_the_environment_file_says(): void
    {
        // config/checkout.php is evaluated as production would evaluate it.
        $evaluate = function (string $environment, ?string $setting): bool {
            $before = [$_ENV['APP_ENV'] ?? null, $_ENV['CHECKOUT_ALLOW_TEST_RULES'] ?? null, $_SERVER['APP_ENV'] ?? null, $_SERVER['CHECKOUT_ALLOW_TEST_RULES'] ?? null, getenv('APP_ENV'), getenv('CHECKOUT_ALLOW_TEST_RULES')];
            foreach (['APP_ENV' => $environment, 'CHECKOUT_ALLOW_TEST_RULES' => $setting] as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key], $_SERVER[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
            try {
                return (bool) (require config_path('checkout.php'))['financial_rules']['allow_test'];
            } finally {
                foreach (['APP_ENV' => [$before[0], $before[2], $before[4]], 'CHECKOUT_ALLOW_TEST_RULES' => [$before[1], $before[3], $before[5]]] as $key => [$env, $server, $process]) {
                    if ($env === null) {
                        unset($_ENV[$key]);
                    } else {
                        $_ENV[$key] = $env;
                    }
                    if ($server === null) {
                        unset($_SERVER[$key]);
                    } else {
                        $_SERVER[$key] = $server;
                    }
                    $process === false ? putenv($key) : putenv("{$key}={$process}");
                }
            }
        };

        $this->assertFalse($evaluate('production', null));
        $this->assertFalse($evaluate('production', 'true'), 'not even when somebody sets it');
        $this->assertFalse($evaluate('production', '1'));
        $this->assertTrue($evaluate('local', null));
        $this->assertTrue($evaluate('local', ''), 'an empty line in .env is "not set"');
        $this->assertTrue($evaluate('testing', 'true'));
        $this->assertFalse($evaluate('local', 'false'));
        $this->assertFalse($evaluate('staging', '0'));
    }
}
