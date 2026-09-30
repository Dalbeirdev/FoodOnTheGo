<?php

namespace Tests\Unit\Support;

use App\Support\Logging\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    public function test_sensitive_keys_are_masked_at_any_depth_and_safe_fields_survive(): void
    {
        $redactor = new SensitiveDataRedactor(['password', 'otp', 'cvv', 'pin', 'secret', 'token', 'authorization']);

        $redacted = $redactor->redact([
            'phone' => '+919876543210',
            'password' => 'Secret123',
            'otp_code' => '482913',
            'payment' => ['cvv' => '555', 'upi_pin' => '7777', 'amount' => 24900],
            'headers' => ['Authorization' => 'Bearer 12|abcDEF', 'Accept' => 'application/json'],
            'provider' => ['key_secret' => 'rzp_test_secret', 'driver' => 'razorpay'],
            'access-token' => 'abc',
        ]);

        $this->assertSame('+919876543210', $redacted['phone']);
        $this->assertSame(24900, $redacted['payment']['amount']);
        $this->assertSame('application/json', $redacted['headers']['Accept']);
        $this->assertSame('razorpay', $redacted['provider']['driver']);

        $serialised = json_encode($redacted);
        foreach (['Secret123', '482913', '555', '7777', 'abcDEF', 'rzp_test_secret', '"abc"'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised);
        }
    }

    public function test_bearer_tokens_inside_free_text_are_masked(): void
    {
        $redactor = new SensitiveDataRedactor([]);

        $this->assertSame(
            'upstream rejected Bearer [REDACTED] for request',
            $redactor->redactText('upstream rejected Bearer 12|s3cr3tT0ken.value for request'),
        );
    }
}
