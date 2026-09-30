<?php

namespace Tests\Unit\Support;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 appendix B secret ("12345678901234567890" in ASCII). */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_codes_match_the_rfc_6238_sha1_test_vectors(): void
    {
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037'] as $time => $expected) {
            $this->assertSame($expected, Totp::codeAt(self::SECRET, $time, 8));
            $this->assertSame(substr($expected, -6), Totp::codeAt(self::SECRET, $time));
        }
    }

    public function test_verification_allows_one_step_of_clock_drift_and_returns_the_matched_step(): void
    {
        $now = 1_700_000_000;
        $step = intdiv($now, 30);

        $this->assertSame($step, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $now), $now));
        $this->assertSame($step - 1, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $now - 30), $now));
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $now - 90), $now));
        $this->assertNull(Totp::verify(self::SECRET, '12345', $now));
        $this->assertNull(Totp::verify(self::SECRET, 'abcdef', $now));
    }

    public function test_generated_secrets_are_random_base32_and_round_trip(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
    }

    public function test_provisioning_uri_names_issuer_and_account(): void
    {
        $uri = Totp::provisioningUri(self::SECRET, 'alex@foodonthego.example', 'FoodOnTheGo');

        $this->assertStringStartsWith('otpauth://totp/FoodOnTheGo%3Aalex%40foodonthego.example?', $uri);
        $this->assertStringContainsString('secret='.self::SECRET, $uri);
        $this->assertStringContainsString('issuer=FoodOnTheGo', $uri);
    }
}
