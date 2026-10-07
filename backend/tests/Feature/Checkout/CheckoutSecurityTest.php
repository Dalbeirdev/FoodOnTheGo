<?php

namespace Tests\Feature\Checkout;

use App\Enums\CustomerStatus;
use App\Enums\Permission;
use App\Models\CheckoutSession;
use App\Models\PickupSlotReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * Who may use a checkout, and what a request may say (Module 29): a signed-in customer, their own checkout only;
 * no amount, no identity, no state and no credential is ever taken from a request.
 */
class CheckoutSecurityTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    private array $asha;

    private array $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        [, $this->asha] = $this->customerReadyForCheckout();   // ₹500
        $this->checkout = $this->openCheckout($this->asha)->assertCreated()->json('checkout');
    }

    private function url(string $suffix = '', ?string $id = null): string
    {
        return '/api/v1/checkouts/'.($id ?? $this->checkout['id']).$suffix;
    }

    public function test_checkout_needs_a_signed_in_customer_a_guest_cart_is_not_enough(): void
    {
        $guest = $this->guestWithBurger();
        $this->selectAsap($guest)->assertOk();

        $this->postJson('/api/v1/checkouts', [], $guest)->assertStatus(401);
        $this->getJson($this->url(), $guest)->assertStatus(401);
        $this->postJson($this->url('/confirm'), [], $guest)->assertStatus(401);
        $this->postJson($this->url('/promotion'), ['code' => 'X'], $guest)->assertStatus(401);
        $this->postJson($this->url('/cancel'), [], $guest)->assertStatus(401);
        $this->assertSame(1, CheckoutSession::query()->count());
    }

    public function test_a_checkout_is_found_only_for_the_customer_it_belongs_to(): void
    {
        $this->promotion(['code' => 'SAVE100']);
        [, $ravi] = $this->customerReadyForCheckout(1, ['Size' => ['Regular']], null, '+919876543211');

        // Every route answers "does not exist" — the same as for an id nobody has.
        foreach ([$this->checkout['id'], '0199c9d2-7f7b-7c8a-9d57-3d5e6f708192'] as $id) {
            $this->getJson($this->url('', $id), $this->using($ravi))->assertNotFound()->assertJsonPath('error.code', 'checkout_not_found');
            $this->postJson($this->url('/promotion', $id), ['code' => 'SAVE100'], $ravi)->assertNotFound()->assertJsonPath('error.code', 'checkout_not_found');
            $this->deleteJson($this->url('/promotion', $id), [], $ravi)->assertNotFound();
            $this->confirmCheckout($ravi, [...$this->checkout, 'id' => $id])->assertNotFound()->assertJsonPath('error.code', 'checkout_not_found');
            $this->postJson($this->url('/cancel', $id), [], $this->using($ravi))->assertNotFound();
        }
        $this->getJson($this->url('', 'not-an-id'), $ravi)->assertNotFound();

        // Asha's checkout is untouched: still a draft, no promotion, not cancelled.
        $this->showCheckout($this->asha, $this->checkout['id'])->assertOk()->assertJsonPath('checkout.status', 'DRAFT')->assertJsonPath('checkout.promotion', null);
        // Opening a checkout never takes an id: Ravi gets his own, for his own cart.
        $own = $this->openCheckout($ravi)->assertCreated()->json('checkout');
        $this->assertNotSame($this->checkout['id'], $own['id']);
        $this->assertSame(['+919876543211', 1, $this->inr(20000)], [$own['customer']['phone'], $own['item_count'], $own['pricing']['total']]);
    }

    public function test_restaurant_staff_and_administrators_cannot_use_or_read_a_customers_checkout(): void
    {
        $this->actingAsPrincipal($this->staff());
        $this->getJson($this->url())->assertStatus(401);
        $this->postJson('/api/v1/checkouts')->assertStatus(401);
        $this->postJson($this->url('/confirm'))->assertStatus(401);
        // Nothing in the restaurant or admin API lists or shows a checkout.
        foreach (['/api/v1/restaurant/checkouts', '/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/checkouts', '/api/v1/admin/checkouts', '/api/v1/admin/checkouts/'.$this->checkout['id']] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        // The owner of the restaurant cannot reach tax, fee or promotion configuration either.
        $this->getJson('/api/v1/admin/promotions')->assertStatus(401);
        $this->getJson('/api/v1/admin/markets/'.$this->market->public_id.'/financial-rules')->assertStatus(401);

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView, Permission::AdminPromotionsManage, Permission::AdminMarketConfigurationManage]));
        $this->getJson($this->url())->assertStatus(401);
        $this->postJson($this->url('/cancel'))->assertStatus(401);
    }

    public function test_an_account_that_cannot_be_used_cannot_check_out(): void
    {
        $this->cartModel($this->asha)->customer->forceFill(['status' => CustomerStatus::Suspended])->save();

        $this->getJson($this->url(), $this->using($this->asha))->assertStatus(403)->assertJsonPath('error.code', 'account_not_active');
        $this->postJson('/api/v1/checkouts', [], $this->using($this->asha))->assertStatus(403);
        $this->postJson($this->url('/confirm'), [], $this->using($this->asha))->assertStatus(403);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function tampering(): array
    {
        return [
            'a lower total' => [['total' => 1], 'total'],
            'a total in minor units' => [['total_minor' => 100], 'total_minor'],
            'an amount to pay' => [['amount' => ['amount' => 100, 'currency' => 'INR']], 'amount'],
            'a payment amount' => [['payment_amount' => 100], 'payment_amount'],
            'a subtotal' => [['subtotal_minor' => 100], 'subtotal_minor'],
            'a bigger discount' => [['discount_minor' => 49900], 'discount_minor'],
            'a promotion value' => [['promotion_value' => 50000], 'promotion_value'],
            'no tax' => [['tax_minor' => 0], 'tax_minor'],
            'no fees' => [['fees' => [['code' => 'PLATFORM_FEE', 'amount' => 0]]], 'fees'],
            'a whole pricing block' => [['pricing' => ['total' => ['amount' => 1, 'currency' => 'INR']]], 'pricing'],
            'another currency' => [['currency' => 'USD'], 'currency'],
            'another customer' => [['customer_id' => 1], 'customer_id'],
            'another cart' => [['cart_id' => '0199c9d2-7f7b-7c8a-9d57-3d5e6f708192'], 'cart_id'],
            'another restaurant' => [['restaurant_id' => '0199c9d2-7f7b-7c8a-9d57-3d5e6f708192'], 'restaurant_id'],
            'another market' => [['market_id' => 2], 'market_id'],
            'a status' => [['status' => 'READY'], 'status'],
            'a later expiry' => [['expires_at' => '2030-01-01T00:00:00Z'], 'expires_at'],
            'a device clock' => [['client_time' => '2026-10-07T00:00:00Z'], 'client_time'],
            'a pickup time' => [['pickup_at' => '2026-10-07T07:00:00Z'], 'pickup_at'],
            'capacity' => [['capacity_units' => 0], 'capacity_units'],
            'a hold' => [['hold' => true], 'hold'],
            'another phone number' => [['phone' => '+919000000000'], 'phone'],
            'payment not required' => [['payment_required' => false], 'payment_required'],
            'a card number' => [['card_number' => '4111111111111111'], 'card_number'],
            'a CVV' => [['cvv' => '123'], 'cvv'],
            'a UPI PIN' => [['upi_pin' => '1234'], 'upi_pin'],
            'a UPI id' => [['vpa' => 'someone@upi'], 'vpa'],
            'a bank password' => [['bank_password' => 'secret'], 'bank_password'],
            'a one-time code' => [['otp' => '123456'], 'otp'],
            'cash at pickup' => [['cash_at_pickup' => true], 'cash_at_pickup'],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('tampering')]
    public function test_what_the_server_decides_cannot_be_sent(array $extra, string $field): void
    {
        $before = $this->sessionModel($this->checkout['id'])->getAttributes();

        // On every write of the checkout API: such a field is refused, not ignored — and never read.
        $this->openCheckout($this->asha, $extra)->assertUnprocessable()->assertJsonPath('error.details.fields.'.$field.'.0', 'The '.str_replace('_', ' ', $field).' field is decided by the server and cannot be sent.');
        $this->confirmCheckout($this->asha, $this->checkout, $extra)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        $this->postJson($this->url('/promotion'), ['code' => 'SAVE100', ...$extra], $this->using($this->asha))->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        $this->assertSame($before, $this->sessionModel($this->checkout['id'])->getAttributes(), 'nothing was changed');
        $this->assertSame(0, PickupSlotReservation::query()->count());
    }

    public function test_a_payment_method_is_a_type_or_a_saved_reference_never_credentials(): void
    {
        $refused = function (mixed $method): TestResponse {
            return $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => $method])->assertUnprocessable();
        };

        // Card data inside the payment method, in any shape.
        $refused(['type' => 'CARD', 'card_number' => '4111111111111111', 'cvv' => '123']);
        $refused(['type' => 'CARD', 'card' => ['number' => '4111111111111111', 'expiry' => '12/30']]);
        $refused(['type' => 'UPI', 'upi_pin' => '1234']);
        $refused(['type' => 'NET_BANKING', 'password' => 'secret']);
        $refused('4111111111111111');
        // A saved method is named by its public id — nothing else is a reference.
        $refused(['type' => 'CARD', 'saved_method_id' => '4111111111111111']);
        $refused(['type' => 'CARD', 'saved_method_id' => 42]);

        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);
        // Not one digit of it reached the database or the audit trail.
        foreach (['checkout_sessions', 'checkout_legal_acceptances', 'audit_events', 'idempotency_keys'] as $table) {
            $this->assertStringNotContainsString('4111111111111111', (string) json_encode(DB::table($table)->get()), $table);
        }
    }

    public function test_cash_at_pickup_is_not_offered_and_not_accepted_whatever_is_configured_or_sent(): void
    {
        // Even a market configuration that claims it is active does not make it a checkout method.
        $this->marketConfiguration(['payment' => ['methods' => ['upi' => 'PLANNED', 'card' => 'PLANNED', 'cash_at_pickup' => 'ACTIVE', 'cash' => 'ACTIVE']]]);

        $checkout = $this->showCheckout($this->asha, $this->checkout['id'])->assertOk()->json('checkout');
        $this->assertSame(['UPI', 'CARD'], array_column($checkout['payment_methods']['available'], 'type'));
        $this->assertSame('NOT_APPROVED', $checkout['payment_methods']['cash_at_pickup']);

        foreach (['CASH_AT_PICKUP', 'CASH', 'cash_at_pickup', 'COD', 'PAY_AT_PICKUP'] as $type) {
            $this->confirmCheckout($this->asha, $this->checkout, ['payment_method' => ['type' => $type]])->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_unavailable');
        }
        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);
    }

    public function test_the_order_note_is_plain_text_of_limited_length(): void
    {
        $this->confirmCheckout($this->asha, $this->checkout, ['order_note' => '<script>alert(1)</script>'])->assertUnprocessable()->assertJsonPath('error.details.fields.order_note.0', 'Formatting and HTML are not allowed here — plain text only.');
        $this->confirmCheckout($this->asha, $this->checkout, ['order_note' => '<img src=x onerror=alert(1)>'])->assertUnprocessable();
        $this->confirmCheckout($this->asha, $this->checkout, ['order_note' => str_repeat('a', 301)])->assertUnprocessable();
        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);

        $ready = $this->confirmCheckout($this->asha, $this->checkout, ['order_note' => "  Please pack the sauce separately.\u{0007}\r\n\r\n\r\n\r\nThanks & regards — 2 > 1 "])->assertOk()->json('checkout');

        // Stored and returned as text: control characters gone, never markup, never interpreted.
        $this->assertSame("Please pack the sauce separately.\n\nThanks & regards — 2 > 1", $ready['order_note']);
        $this->assertSame($ready['order_note'], $this->sessionModel($ready['id'])->order_note);
    }

    public function test_requests_are_validated_before_anything_happens(): void
    {
        $this->postJson($this->url('/confirm'), [], $this->using($this->asha))->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['pricing_fingerprint', 'accepted_documents']]]]);
        $this->confirmCheckout($this->asha, $this->checkout, ['pricing_fingerprint' => 'not-a-fingerprint'])->assertUnprocessable();
        $this->confirmCheckout($this->asha, $this->checkout, ['accepted_documents' => [['type' => 'MARKETING', 'version' => '1']]])->assertUnprocessable();
        $this->confirmCheckout($this->asha, $this->checkout, ['accepted_documents' => [['type' => 'TERMS', 'version' => 'draft-2026-09', 'ip' => '10.0.0.1']]])->assertUnprocessable();
        $this->postJson($this->url('/promotion'), [], $this->using($this->asha))->assertUnprocessable();
        $this->postJson($this->url('/promotion'), ['code' => str_repeat('A', 41)], $this->using($this->asha))->assertUnprocessable();
        $this->postJson($this->url('/promotion'), ['code' => ['SAVE100']], $this->using($this->asha))->assertUnprocessable();
        // A code is data, whatever it contains.
        $this->postJson($this->url('/promotion'), ['code' => "' OR 1=1 --"], $this->using($this->asha))->assertUnprocessable()->assertJsonPath('error.code', 'promotion_not_found');
        $this->postJson($this->url('/promotion'), ['code' => '<b>FREE</b>'], $this->using($this->asha))->assertUnprocessable()->assertJsonPath('error.code', 'promotion_not_found');

        $this->assertSame('DRAFT', $this->sessionModel($this->checkout['id'])->status->value);
    }

    public function test_the_answer_carries_no_internal_id_and_no_provider_or_configuration_internals(): void
    {
        $this->taxRule();
        $this->feeRule();
        $this->promotion(['code' => 'SAVE100', 'usage_limit' => 500, 'per_customer_limit' => 3, 'funding_source' => 'SHARED', 'description' => 'Internal: Q4 acquisition budget']);
        $ready = $this->confirmCheckout($this->asha, $this->openCheckout($this->asha, ['promotion_code' => 'SAVE100'])->assertCreated()->json('checkout'))->assertOk();

        $body = $ready->getContent();
        foreach (['customer_id', 'cart_id', 'market_id', 'location_id', 'promotion_id', 'tax_rule_id', 'fee_rule_id', 'rule_id', 'pickup_selection_id', 'usage_limit', 'per_customer_limit', 'redeemed_count', 'funding_source', 'approval', 'capacity', 'units', 'provider_reference', 'request_id', 'ip_address', 'snapshot', 'basis'] as $never) {
            $this->assertStringNotContainsString('"'.$never.'"', $body, $never);
        }
        $this->assertStringNotContainsString('SHARED', $body);
        $this->assertStringNotContainsString('acquisition budget', $body);
        // A tax or fee line is what a customer may see of a rule: its label, its rate, its amount.
        $this->assertSame(['code', 'label', 'applies_to', 'rate_basis_points', 'taxable_amount', 'amount', 'included_in_prices'], array_keys($ready->json('checkout.pricing.taxes.0')));
        $this->assertSame(['code', 'label', 'amount', 'taxable'], array_keys($ready->json('checkout.pricing.fees.0')));
        $this->assertSame(['code', 'name', 'discount'], array_keys($ready->json('checkout.promotion')));
        // This is a checkout summary: not a receipt, not a tax invoice.
        $this->assertStringNotContainsStringIgnoringCase('invoice', $body);
        $this->assertStringNotContainsStringIgnoringCase('receipt', $body);
        $this->assertStringNotContainsStringIgnoringCase('delivery', $body);
    }

    public function test_checkout_events_are_logged_with_ids_and_numbers_only(): void
    {
        Log::spy();
        $this->promotion(['code' => 'SAVE100']);
        $this->postJson($this->url('/promotion'), ['code' => 'SAVE100'], $this->using($this->asha))->assertOk();
        $this->postJson($this->url('/promotion'), ['code' => 'SECRETCODE'], $this->using($this->asha))->assertUnprocessable();
        $ready = $this->confirmCheckout($this->asha, $this->showCheckout($this->asha, $this->checkout['id'])->json('checkout'), ['order_note' => 'Gate 4, call 98765 43210'])->assertOk()->json('checkout');
        $this->postJson($this->url('/cancel', $ready['id']), [], $this->using($this->asha))->assertOk();

        $seen = [];
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use (&$seen): bool {
            if (! str_starts_with($message, 'checkout.')) {
                return false;
            }
            $seen[] = $message;
            $line = $message.' '.json_encode($context);
            foreach (['Classic Burger', 'Gate 4', '98765', '+9198', 'SAVE100', 'SECRETCODE', 'Bearer', 'pay_', 'draft-2026-09', 'Asia/Kolkata'] as $private) {
                $this->assertStringNotContainsString($private, $line, $message);
            }
            $this->assertSame([], array_diff(array_keys($context), ['checkout', 'status', 'currency', 'total_minor', 'reason', 'promotion', 'payment_required']), $message);

            return true;
        })->atLeast()->times(4);
        $this->assertSame(['checkout.promotion_applied', 'checkout.promotion_refused', 'checkout.confirmed', 'checkout.cancelled'], array_values(array_unique($seen)));
    }
}
