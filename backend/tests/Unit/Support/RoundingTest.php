<?php

namespace Tests\Unit\Support;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Services\Checkout\PromotionEligibilityService;
use App\Support\Rounding;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rounding policy of checkout (Module 29): integers only, half up at the minor unit, and parts that always add
 * up to their total. Pure calculation — no database, no float.
 */
class RoundingTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function shares(): array
    {
        return [
            '10 % of ₹500.00' => [50000, 1000, 5000],
            '5 % of ₹199.00 = 9.95 → exact' => [19900, 500, 995],
            '5 % of ₹1.10 = 5.5 paise → 6 (half goes up)' => [110, 500, 6],
            '5 % of ₹1.09 = 5.45 paise → 5' => [109, 500, 5],
            '5 % of ₹0.10 = 0.5 paise → 1' => [10, 500, 1],
            '5 % of ₹0.09 = 0.45 paise → 0' => [9, 500, 0],
            '2.5 % of ₹333.33' => [33333, 250, 833],
            '12.34 % of ₹987.65' => [98765, 1234, 12188],
            '0.01 % of ₹49.99 = 0.4999 paise → 0' => [4999, 1, 0],
            '0.01 % of ₹50.00 = 0.5 paise → 1' => [5000, 1, 1],
            '100 % is the amount' => [12345, 10000, 12345],
            '0 % is nothing' => [12345, 0, 0],
            'nothing of nothing' => [0, 1800, 0],
            // An amount no float could carry exactly: ₹900 billion at 18.18 %.
            'a very large amount stays exact' => [90000000000000, 1818, 16362000000000],
        ];
    }

    #[DataProvider('shares')]
    public function test_a_share_in_basis_points_is_rounded_half_up_to_the_minor_unit(int $amount, int $basisPoints, int $expected): void
    {
        $this->assertSame($expected, Rounding::basisPoints($amount, $basisPoints));
    }

    public function test_the_result_is_always_an_integer_and_never_differs_from_exact_arithmetic(): void
    {
        // Against exact integer arithmetic for every amount up to ₹30 at awkward rates: the result is the nearest
        // minor unit, and exactly-half cases go up.
        foreach ([1, 33, 250, 333, 500, 1234, 1800, 9999] as $rate) {
            for ($amount = 0; $amount <= 3000; $amount++) {
                $share = Rounding::basisPoints($amount, $rate);
                $twice = 2 * $amount * $rate;   // 2 × the exact share × 10000
                $this->assertTrue($twice - 10000 < 2 * $share * 10000 && 2 * $share * 10000 <= $twice + 10000, "{$amount} at {$rate}");
            }
        }
    }

    public function test_division_refuses_what_is_not_an_amount(): void
    {
        $this->assertSame([3, 2, 0, 1], [Rounding::divide(5, 2), Rounding::divide(3, 2), Rounding::divide(1, 3), Rounding::divide(1, 2)]);
        foreach ([[1, 0], [1, -2], [-1, 2]] as [$n, $d]) {
            try {
                Rounding::divide($n, $d);
                $this->fail("divide({$n}, {$d}) was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Rounding::basisPoints(100, -1);
    }

    public function test_the_tax_inside_an_inclusive_price_is_extracted_exactly(): void
    {
        // ₹105.00 including 5 %: ₹100.00 net, ₹5.00 tax.
        $this->assertSame(500, Rounding::includedTax(10500, 500));
        // ₹500.00 including 5 %: 500 − 476.19 = 23.81.
        $this->assertSame(2381, Rounding::includedTax(50000, 500));
        // ₹118.00 including 18 %: ₹18.00.
        $this->assertSame(1800, Rounding::includedTax(11800, 1800));
        $this->assertSame([0, 0, 0], [Rounding::includedTax(0, 500), Rounding::includedTax(10000, 0), Rounding::includedTax(1, 500)]);
        // Net + tax is always the price that was charged — nothing appears, nothing is lost.
        foreach ([1, 99, 100, 101, 4999, 50000, 123457] as $gross) {
            foreach ([1, 250, 500, 1234, 1800, 2800] as $rate) {
                $tax = Rounding::includedTax($gross, $rate);
                $this->assertGreaterThanOrEqual(0, $tax);
                $this->assertLessThanOrEqual($gross, $tax);
                $this->assertSame($gross - $tax, Rounding::divide($gross * 10000, 10000 + $rate));
            }
        }
    }

    public function test_parts_always_add_up_to_their_total(): void
    {
        // Two equal components of ₹23.81: 11.905 each cannot be — 11.91 + 11.90, the earlier part gets the paise.
        $this->assertSame([1191, 1190], Rounding::allocate(2381, [250, 250]));
        $this->assertSame([34, 33, 33], Rounding::allocate(100, [1, 1, 1]));
        $this->assertSame([1, 0, 0], Rounding::allocate(1, [1, 1, 1]));
        $this->assertSame([60, 40], Rounding::allocate(100, [900, 600]));
        // The largest remainder gets the unit that is left: 10 × 5/8 = 6.25, 10 × 3/8 = 3.75.
        $this->assertSame([6, 4], Rounding::allocate(10, [5, 3]));
        $this->assertSame([0, 0], Rounding::allocate(500, [0, 0]));
        $this->assertSame([], Rounding::allocate(500, []));
        $this->assertSame([0, 500], Rounding::allocate(500, [0, 7]));

        foreach ([[2381, [250, 250]], [9999, [1, 2, 3, 4]], [7, [3, 3, 3, 3, 3, 3, 3, 3]], [1000003, [900, 900, 28]], [1, [1, 9999]]] as [$total, $weights]) {
            $parts = Rounding::allocate($total, $weights);
            $this->assertSame($total, array_sum($parts));
            $this->assertCount(count($weights), $parts);
            $this->assertSame([], array_filter($parts, fn (int $p): bool => $p < 0));
        }
        $this->expectException(InvalidArgumentException::class);
        Rounding::allocate(100, [1, -1]);
    }

    public function test_a_discount_is_an_integer_and_never_more_than_the_merchandise(): void
    {
        $fixed = fn (int $amount): Promotion => (new Promotion)->forceFill(['type' => PromotionType::FixedAmount->value, 'amount_minor' => $amount]);
        $percent = fn (int $rate, ?int $max = null): Promotion => (new Promotion)->forceFill(['type' => PromotionType::Percentage->value, 'percent_basis_points' => $rate, 'maximum_discount_minor' => $max]);
        $discount = PromotionEligibilityService::discount(...);

        $this->assertSame(10000, $discount($fixed(10000), 50000));
        // More than the subtotal is the subtotal: a total never goes below zero.
        $this->assertSame(4000, $discount($fixed(10000), 4000));
        $this->assertSame(0, $discount($fixed(10000), 0));
        $this->assertSame(5000, $discount($percent(1000), 50000));
        // 15 % of ₹333.33 = 49.9995 → ₹50.00.
        $this->assertSame(5000, $discount($percent(1500), 33333));
        // A maximum caps a percentage; below it the percentage stands.
        $this->assertSame(7500, $discount($percent(2000, 7500), 50000));
        $this->assertSame(2000, $discount($percent(2000, 7500), 10000));
        $this->assertSame(12345, $discount($percent(10000), 12345));
        $this->assertSame(1, $discount($percent(1), 5000));
    }
}
