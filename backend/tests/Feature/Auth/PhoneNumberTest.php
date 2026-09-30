<?php

namespace Tests\Feature\Auth;

use App\Models\Market;
use App\Support\PhoneNumber;
use InvalidArgumentException;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    private function india(): Market
    {
        return Market::factory()->india()->make();
    }

    public function test_equivalent_india_formats_normalise_to_one_e164_number(): void
    {
        foreach (['9876543210', '98765 43210', '098765 43210', '+91 98765 43210', '+91-98765-43210', '0091 9876543210', '91 98765 43210', '(+91) 98765.43210'] as $input) {
            $this->assertSame('+919876543210', PhoneNumber::parse($input, $this->india())->e164, "input: {$input}");
        }
    }

    public function test_invalid_india_numbers_are_rejected_by_the_market_rule(): void
    {
        foreach (['12345', '5876543210', '98765432100', 'phone', '+91 98765', '98765-4321x', ''] as $input) {
            try {
                PhoneNumber::parse($input, $this->india());
                $this->fail("Accepted invalid number [{$input}]");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_dialling_code_and_rule_come_from_the_market_not_from_code(): void
    {
        $emirates = Market::factory()->make([
            'country_code' => 'AE', 'name' => 'United Arab Emirates', 'phone_country_code' => '+971',
            'phone_national_pattern' => '^5[0-9]{8}$', 'phone_trunk_prefix' => '0',
        ]);

        $this->assertSame('+971501234567', PhoneNumber::parse('050 123 4567', $emirates)->e164);
        $this->assertSame('+971501234567', PhoneNumber::parse('+971 50 123 4567', $emirates)->e164);

        // A ten-digit Indian mobile is not valid in this market, and an Emirati number is not valid in India.
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::parse('+971 50 123 4567', $this->india());
    }

    public function test_a_market_without_a_national_rule_still_enforces_e164_bounds(): void
    {
        $generic = Market::factory()->make(['phone_country_code' => '+65', 'phone_national_pattern' => null, 'phone_trunk_prefix' => null]);

        $this->assertSame('+6591234567', PhoneNumber::parse('9123 4567', $generic)->e164);

        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::parse('12', $generic);
    }

    public function test_masking_hides_all_but_the_last_four_digits_and_hash_is_not_the_number(): void
    {
        $phone = PhoneNumber::parse('9876543210', $this->india());

        $this->assertSame('+91 ******3210', $phone->masked());
        $this->assertSame('*********3210', PhoneNumber::mask('+919876543210'));
        $this->assertSame(64, strlen($phone->hash()));
        $this->assertStringNotContainsString('9876543210', $phone->hash());
    }
}
