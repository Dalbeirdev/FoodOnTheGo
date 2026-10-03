<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Services\Customer\PaymentMethodReferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Payment-method references (Module 25): the security boundary. No credential is ever accepted or stored, the
 * provider references never leave the server, one default per customer, removal keeps the row as REVOKED,
 * ownership is enforced.
 */
class PaymentMethodsApiTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase;

    private Customer $rahul;

    private PaymentMethodReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->rahul = $this->customer($this->market, ['name' => 'Rahul Sharma']);
        $this->service = app(PaymentMethodReferenceService::class);
        $this->actingAsPrincipal($this->rahul);
    }

    private function visa(Customer $customer, string $ref = 'dev_pm_visa_4242', bool $default = true): CustomerPaymentMethod
    {
        return $this->service->attach($customer, ['type' => 'CARD', 'provider' => 'development', 'provider_customer_reference' => 'dev_cust_1', 'provider_payment_method_reference' => $ref, 'brand' => 'Visa', 'display_label' => 'Visa •••• 4242', 'last4' => '4242', 'expiry_month' => 12, 'expiry_year' => 2028, 'is_default' => $default]);
    }

    public function test_the_list_shows_safe_metadata_only_and_never_a_provider_reference(): void
    {
        $this->visa($this->rahul);
        $this->service->attach($this->rahul, ['type' => 'UPI', 'provider' => 'development', 'provider_payment_method_reference' => 'dev_pm_upi_1', 'display_label' => 'UPI ra***@okaxis', 'upi_handle_masked' => 'ra***@okaxis']);
        $response = $this->getJson('/api/v1/customer/payment-methods')->assertOk();
        $list = $response->json();
        $this->assertSame(['CARD', 'UPI'], array_column($list, 'type'));
        $this->assertSame(['Visa', 'Visa •••• 4242', '4242', ['month' => 12, 'year' => 2028], true, 'ACTIVE', 'development'], [$list[0]['brand'], $list[0]['display_label'], $list[0]['last4'], $list[0]['expiry'], $list[0]['is_default'], $list[0]['status'], $list[0]['provider']]);
        $this->assertSame('ra***@okaxis', $list[1]['upi_handle_masked']);
        $body = $response->getContent();
        foreach (['dev_pm_visa_4242', 'dev_pm_upi_1', 'dev_cust_1', 'provider_payment_method_reference', 'provider_customer_reference', 'reference_hash', 'card_number', 'cvv'] as $never) {
            $this->assertStringNotContainsString($never, $body, $never);
        }
        // references are encrypted at rest
        $raw = DB::table('customer_payment_methods')->where('display_label', 'Visa •••• 4242')->value('provider_payment_method_reference');
        $this->assertNotSame('dev_pm_visa_4242', $raw);
        $this->assertSame('dev_pm_visa_4242', CustomerPaymentMethod::query()->where('display_label', 'Visa •••• 4242')->firstOrFail()->provider_payment_method_reference);
    }

    public function test_no_route_accepts_raw_payment_credentials(): void
    {
        $payload = ['type' => 'CARD', 'card_number' => '4242424242424242', 'cvv' => '123', 'expiry' => '12/28', 'upi_pin' => '1234'];
        $this->postJson('/api/v1/customer/payment-methods', $payload)->assertStatus(405);
        $this->putJson('/api/v1/customer/payment-methods', $payload)->assertStatus(405);
        $visa = $this->visa($this->rahul);
        $this->patchJson('/api/v1/customer/payment-methods/'.$visa->public_id.'/default', ['card_number' => '4242424242424242', 'cvv' => '123'])->assertUnprocessable()->assertJsonPath('error.details.fields.card_number.0', 'FoodOnTheGo never receives payment credentials. Payment methods are added through the payment provider.');
        $this->assertSame(1, DB::table('customer_payment_methods')->count());
        $this->assertStringNotContainsString('4242424242424242', json_encode(DB::table('customer_payment_methods')->get()));
        $this->assertStringNotContainsString('4242424242424242', json_encode(DB::table('audit_events')->get()));
    }

    public function test_only_one_default_survives_two_default_requests_and_an_unusable_method_cannot_become_default(): void
    {
        $a = $this->visa($this->rahul, 'ref_a');
        $b = $this->visa($this->rahul, 'ref_b', false);
        $expired = $this->service->attach($this->rahul, ['type' => 'CARD', 'provider' => 'development', 'provider_payment_method_reference' => 'ref_old', 'brand' => 'Mastercard', 'display_label' => 'Mastercard •••• 4444', 'last4' => '4444', 'expiry_month' => 1, 'expiry_year' => 2024]);
        $this->patchJson('/api/v1/customer/payment-methods/'.$b->public_id.'/default')->assertOk();
        $this->patchJson('/api/v1/customer/payment-methods/'.$a->public_id.'/default')->assertOk();
        $this->patchJson('/api/v1/customer/payment-methods/'.$b->public_id.'/default')->assertOk();
        $this->assertSame([$b->public_id], DB::table('customer_payment_methods')->where('is_default', true)->pluck('public_id')->all());
        $this->patchJson('/api/v1/customer/payment-methods/'.$expired->public_id.'/default')->assertUnprocessable()->assertJsonPath('error.code', 'payment_method_not_usable');
        $list = $this->getJson('/api/v1/customer/payment-methods')->json();
        $this->assertSame('EXPIRED', collect($list)->firstWhere('id', $expired->public_id)['status']);
        $this->assertSame('EXPIRED', $expired->fresh()->status->value, 'the expiry is written back as it is read');
        $this->expectException(QueryException::class);
        DB::table('customer_payment_methods')->where('public_id', $a->public_id)->update(['is_default' => true]);
    }

    public function test_removing_marks_the_reference_revoked_keeps_the_row_and_promotes_another_default(): void
    {
        $a = $this->visa($this->rahul, 'ref_a');
        $b = $this->visa($this->rahul, 'ref_b', false);
        $list = $this->deleteJson('/api/v1/customer/payment-methods/'.$a->public_id)->assertOk()->json();
        $this->assertSame([$b->public_id], array_column($list, 'id'));
        $this->assertTrue($list[0]['is_default']);
        $this->assertSame(['REVOKED', false], [$a->fresh()->status->value, (bool) $a->fresh()->is_default]);
        $this->assertNotNull($a->fresh()->revoked_at);
        $this->assertDatabaseCount('customer_payment_methods', 2); // the row stays for the payment history of later modules
        $this->deleteJson('/api/v1/customer/payment-methods/'.$a->public_id)->assertOk(); // idempotent
        $this->assertSame('customer.payment_method_revoked', DB::table('audit_events')->where('target_type', 'customer_payment_methods')->latest('id')->value('action'));
    }

    public function test_another_customer_cannot_see_default_or_remove_my_methods(): void
    {
        $a = $this->visa($this->rahul);
        $asha = $this->customer($this->market);
        $this->actingAsPrincipal($asha);
        $this->assertSame([], $this->getJson('/api/v1/customer/payment-methods')->json());
        $this->patchJson('/api/v1/customer/payment-methods/'.$a->public_id.'/default')->assertNotFound()->assertJsonPath('error.code', 'payment_method_not_found');
        $this->deleteJson('/api/v1/customer/payment-methods/'.$a->public_id)->assertNotFound();
        $this->assertSame('ACTIVE', $a->fresh()->status->value);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/payment-methods')->assertUnauthorized();
    }
}
