<?php

namespace Tests\Unit\Cart;

use App\Exceptions\ApiException;
use App\Services\Cart\CartSignature;
use App\Services\Cart\GuestCartToken;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The two small pieces everything else in the cart leans on: when two lines are the same, and which guest
 * tokens are ours.
 */
class CartSignatureTest extends TestCase
{
    private const ITEM = '11111111-1111-4111-8111-111111111111';

    private const A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public function test_the_signature_depends_on_item_options_and_instructions_only(): void
    {
        $base = CartSignature::of(self::ITEM, [self::A, self::B], 'No onions');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
        // The order of the options, repeated ids, and the spelling of white space / case in the note do not matter.
        $this->assertSame($base, CartSignature::of(self::ITEM, [self::B, self::A], 'No onions'));
        $this->assertSame($base, CartSignature::of(self::ITEM, [self::A, self::B, self::A], 'No onions'));
        $this->assertSame($base, CartSignature::of(self::ITEM, [self::A, self::B], "  no   ONIONS\t"));

        // Another item, another option set, another note: another line.
        $this->assertNotSame($base, CartSignature::of('22222222-2222-4222-8222-222222222222', [self::A, self::B], 'No onions'));
        $this->assertNotSame($base, CartSignature::of(self::ITEM, [self::A], 'No onions'));
        $this->assertNotSame($base, CartSignature::of(self::ITEM, [self::A, self::B], 'Extra onions'));
        $this->assertNotSame($base, CartSignature::of(self::ITEM, [self::A, self::B], null));
        $this->assertSame(CartSignature::of(self::ITEM, [], null), CartSignature::of(self::ITEM, [], ''));
        // Ids cannot be glued into each other.
        $this->assertNotSame(CartSignature::of('ab', ['c'], null), CartSignature::of('a', ['bc'], null));
    }

    public function test_instructions_are_cleaned_bounded_and_kept_as_text(): void
    {
        $this->assertNull(CartSignature::cleanInstructions(null));
        $this->assertNull(CartSignature::cleanInstructions(" \n\t "));
        $this->assertSame('no onions, please', CartSignature::cleanInstructions("  no\u{0007}  onions,\r\nplease "));
        $this->assertSame('<b>bold</b> & "quoted"', CartSignature::cleanInstructions('<b>bold</b> & "quoted"'));
        $this->assertSame("caf\u{00E9}", CartSignature::cleanInstructions("cafe\u{0301}"));
        $this->assertSame(str_repeat('न', 200), CartSignature::cleanInstructions(str_repeat('न', 200)));

        foreach ([str_repeat('x', 201), "\xC3\x28 invalid utf-8"] as $bad) {
            try {
                CartSignature::cleanInstructions($bad);
                $this->fail('accepted');
            } catch (ApiException $e) {
                $this->assertSame([422, 'validation_failed'], [$e->status, $e->errorCode]);
            }
        }
    }

    public function test_only_tokens_this_server_minted_are_recognised(): void
    {
        $tokens = app(GuestCartToken::class);
        $at = CarbonImmutable::parse('2026-10-07 07:30:00', 'UTC');
        $token = $tokens->mint($at);

        $this->assertMatchesRegularExpression('/^gc1\.[0-9a-z]+\.[0-9a-f]{64}\.[0-9a-f]{32}$/', $token);
        $this->assertNotSame($token, $tokens->mint($at), '256 random bits each time');
        $parsed = $tokens->parse($token);
        $this->assertSame([hash('sha256', $token), $at->getTimestamp()], [$parsed['hash'], $parsed['issued_at']->getTimestamp()]);

        // Anything altered, truncated, re-dated or made up is nobody's token.
        [$version, $issued, $random, $tag] = explode('.', $token);
        $forged = [
            null, '', 'gc1', $version.'.'.$issued.'.'.$random, $version.'.'.$issued.'.'.$random.'.'.str_repeat('0', 32), $version.'.'.$issued.'.'.str_repeat('a', 64).'.'.$tag,
            $version.'.'.base_convert((string) ($at->getTimestamp() + 86400), 10, 36).'.'.$random.'.'.$tag, 'gc2.'.$issued.'.'.$random.'.'.$tag, strtoupper($token), $token.' ', ' '.$token,
        ];
        foreach ($forged as $bad) {
            $this->assertNull($tokens->parse($bad), (string) $bad);
        }

        // Another application key signs differently: a token of another environment is not accepted here.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNull(app(GuestCartToken::class)->parse($token));
    }
}
