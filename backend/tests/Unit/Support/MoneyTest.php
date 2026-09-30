<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_is_integer_minor_units_with_an_iso_currency_and_serialises_consistently(): void
    {
        $price = new Money(24900, 'INR');

        $this->assertSame(['amount' => 24900, 'currency' => 'INR'], $price->toArray());
        $this->assertSame('{"amount":24900,"currency":"INR"}', json_encode($price));
        $this->assertTrue($price->equals(Money::fromArray(['amount' => 24900, 'currency' => 'INR'])));
    }

    public function test_arithmetic_stays_exact_where_floats_would_drift(): void
    {
        $total = Money::zero('INR');
        for ($i = 0; $i < 10; $i++) {
            $total = $total->plus(new Money(10, 'INR'));
        }

        $this->assertSame(100, $total->amount);
        $this->assertSame(74700, (new Money(24900, 'INR'))->times(3)->amount);
        $this->assertSame(-100, (new Money(400, 'INR'))->minus(new Money(500, 'INR'))->amount);
        $this->assertTrue((new Money(-1, 'INR'))->isNegative());
    }

    public function test_basis_points_round_half_up_in_integer_arithmetic(): void
    {
        $this->assertSame(1245, (new Money(24900, 'INR'))->basisPoints(500)->amount);
        $this->assertSame(1, (new Money(10, 'INR'))->basisPoints(500)->amount);
        $this->assertSame(0, (new Money(9, 'INR'))->basisPoints(500)->amount);
    }

    public function test_currencies_never_mix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(100, 'INR'))->plus(new Money(100, 'USD'));
    }

    public function test_symbols_lowercase_codes_and_float_amounts_are_rejected(): void
    {
        foreach (['₹', 'inr', 'RUPEE', ''] as $invalid) {
            try {
                new Money(100, $invalid);
                $this->fail("Currency [{$invalid}] should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Money::fromArray(['amount' => 249.00, 'currency' => 'INR']);
    }

    public function test_minor_unit_digits_come_from_currency_data_not_from_an_assumption_of_two(): void
    {
        $this->assertSame(2, Money::zero('INR')->fractionDigits());
        $this->assertSame(0, Money::zero('JPY')->fractionDigits());
        $this->assertSame(3, Money::zero('KWD')->fractionDigits());
    }
}
