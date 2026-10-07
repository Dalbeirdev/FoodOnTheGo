<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPayments;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * The payment API is what its document says (Module 30): every route is documented, every answer matches its schema,
 * and no request body has room for an amount, a status or a payment credential.
 */
class PaymentOpenApiContractTest extends TestCase
{
    use BuildsPayments, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_customer_answers_match_their_documented_schemas_in_every_state(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($headers, $checkout['id'])->assertOk()->json());

        // provider ready → cancelled → a second attempt → pending → verified
        $first = $this->startPayment($headers, $checkout)->assertCreated()->json();
        $this->assertMatchesSchema('PaymentEnvelope', $first);
        $this->assertMatchesSchema('PaymentEnvelope', $this->startPayment($headers, $checkout)->assertOk()->json());
        $this->assertMatchesSchema('CheckoutEnvelope', $this->showCheckout($headers, $checkout['id'])->assertOk()->json());   // PAYMENT_PENDING, with payment.attempt
        $this->assertMatchesSchema('PaymentEnvelope', $this->completePayment($headers, $first['payment']['id'], ['outcome' => 'cancelled'])->assertOk()->assertJsonPath('payment.status', 'CANCELLED')->json());

        $second = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $pending = $this->providerPays($second['id'], 'created');
        $this->travel(15)->seconds();
        $this->assertMatchesSchema('PaymentEnvelope', $this->showPayment($headers, $second['id'])->assertOk()->assertJsonPath('payment.status', 'PENDING')->json());
        $paid = $this->fakeProvider()->settle($pending->reference, 'captured');
        $this->assertMatchesSchema('PaymentEnvelope', $this->completePayment($headers, $second['id'], $this->proofOf($second['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'VERIFIED')->json());
        $this->assertMatchesSchema('WebhookReceipt', $this->providerSends('payment.captured', $paid)->assertOk()->json());
        $this->assertMatchesSchema('WebhookReceipt', $this->providerSends('payment.captured', $paid, 'evt_contract_0001')->assertOk()->json());
        $this->assertMatchesSchema('WebhookReceipt', $this->providerSends('payment.captured', $paid, 'evt_contract_0001')->assertOk()->assertJsonPath('duplicate', true)->json());

        // failed, with the provider's reason mapped to a category
        [, $other, , $third] = $this->openPayment('+919811122233');
        $failed = $this->providerPays($third['id'], 'failed', ['error_reason' => 'incorrect_otp']);
        $this->assertMatchesSchema('PaymentEnvelope', $this->completePayment($other, $third['id'], ['outcome' => 'failed', 'provider_error' => ['code' => $failed->errorCode, 'reason' => $failed->errorReason]])->assertOk()->assertJsonPath('payment.failure.category', 'AUTHENTICATION_FAILED')->json());

        // errors use the common envelope
        foreach ([
            $this->startPayment($headers, $checkout)->assertStatus(409),
            $this->startPayment($headers, $checkout, ['amount' => 1])->assertStatus(422),
            $this->showPayment($other, $second['id'])->assertNotFound(),
            $this->completePayment($other, $third['id'], ['outcome' => 'success'])->assertStatus(422),
        ] as $error) {
            $this->assertMatchesSchema('Error', $error->json());
        }
    }

    public function test_the_development_simulator_and_the_admin_answers_match_their_schemas(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $simulated = $this->postJson('/api/v1/dev/payments/'.$payment['id'].'/simulate', ['scenario' => 'SUCCESS', 'webhook' => 'duplicate', 'method' => 'CARD'], $this->using($headers))->assertOk()->json();
        $this->assertMatchesSchema('FakePaymentSimulation', $simulated);
        $this->assertFalse($simulated['simulation']['money_moved']);
        $this->assertSame(['ACCEPTED', 'DUPLICATE'], array_column($simulated['simulation']['webhooks'], 'result'));
        // What the simulator hands back is exactly a completion request — and the payment is verified by the domain, not by the simulator.
        $this->completePayment($headers, $payment['id'], $simulated['simulation']['client'])->assertOk()->assertJsonPath('payment.status', 'VERIFIED');

        $this->seed(RoleSeeder::class);
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', 'SUPER_ADMIN')->firstOrFail(), null);
        $this->actingAsPrincipal($admin);
        $this->assertMatchesSchema('AdminPaymentPage', $this->getJson('/api/v1/admin/payments')->assertOk()->json());
        $this->assertMatchesSchema('AdminPaymentEnvelope', $this->getJson('/api/v1/admin/payments/'.$payment['id'])->assertOk()->json());
        $this->assertMatchesSchema('AdminPaymentEnvelope', $this->postJson('/api/v1/admin/payments/'.$payment['id'].'/reconcile', ['reason' => 'Contract check'])->assertOk()->json());
        $this->assertMatchesSchema('PaymentMetrics', $this->getJson('/api/v1/admin/system/payments')->assertOk()->json());
        $this->assertMatchesSchema('CheckoutMetrics', $this->getJson('/api/v1/admin/system/checkout')->assertOk()->json());
    }

    public function test_every_payment_route_is_documented_and_no_request_has_room_for_an_amount_a_status_or_a_credential(): void
    {
        $api = $this->openApi();
        $paths = $api['paths'];
        $mine = fn (string $uri): bool => (bool) preg_match('#payment-attempts|^payments/|^webhooks/payments|^dev/payments|^admin/payments|^admin/system/payments#', $uri);
        $documented = [];
        foreach ($paths as $path => $operations) {
            if ($mine(ltrim($path, '/'))) {
                foreach (array_keys($operations) as $method) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => [preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])])->filter(fn (array $r): bool => $mine($r[0]))
            ->flatMap(fn (array $r) => array_map(fn (string $m): string => $m.' /'.$r[0], $r[1]))->values()->all();
        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(9, $routes);

        // The webhook has no session of any kind; the customer operations name nothing but the checkout or the payment.
        $this->assertSame([], $paths['/webhooks/payments/{paymentProvider}']['post']['security']);
        foreach (['/checkouts/{checkout}/payment-attempts', '/payments/{payment}', '/payments/{payment}/complete'] as $path) {
            foreach ($paths[$path] as $operation) {
                $this->assertSame([['customer' => []]], $operation['security']);
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $this->assertContains($parameter['name'] ?? basename($parameter['$ref']), ['checkout', 'payment', 'IdempotencyKeyOptional']);
                }
            }
        }
        $schemas = $api['components']['schemas'];
        $forbidden = ['amount', 'amount_minor', 'currency', 'total', 'status', 'verified', 'verified_at', 'paid', 'customer_id', 'checkout_id', 'provider', 'provider_order_reference', 'card', 'card_number', 'number', 'cvv', 'cvc', 'upi_pin', 'pin', 'vpa', 'upi_id', 'password', 'bank_password', 'otp', 'key_secret', 'secret'];
        foreach (['PaymentStartRequest', 'PaymentCompleteRequest', 'AdminPaymentReconcileRequest', 'FakePaymentSimulationRequest'] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], $name);
            $this->assertSame([], array_values(array_intersect(array_keys($schemas[$name]['properties']), $forbidden)), $name);
        }
        $this->assertFalse($schemas['PaymentStartRequest']['properties']['payment_method']['additionalProperties']);
        $this->assertSame(['type'], array_keys($schemas['PaymentStartRequest']['properties']['payment_method']['properties']));
        // The documented states are the states of the code, and what does not exist is said so.
        $this->assertSame(array_map(fn (PaymentStatus $s): string => $s->value, PaymentStatus::cases()), $schemas['Payment']['properties']['status']['enum']);
        $this->assertSame(['NOT_IMPLEMENTED'], $schemas['Payment']['properties']['fulfillment']['properties']['order_creation']['enum']);
        $this->assertSame('null', $schemas['Payment']['properties']['fulfillment']['properties']['order_id']['type']);
        $this->assertSame([false], $schemas['AdminPaymentDetail']['properties']['actions']['properties']['mark_paid']['enum']);
        $this->assertSame(['NOT_IMPLEMENTED'], $schemas['AdminPaymentDetail']['properties']['actions']['properties']['refund']['enum']);
        // Every amount is Money (integer minor units + currency) or an integer: no float anywhere in the payment schemas.
        $numbers = [];
        foreach (['Payment', 'PaymentProviderSession', 'AdminPayment', 'AdminPaymentDetail'] as $name) {
            array_walk_recursive($schemas[$name], function ($value, $key) use (&$numbers): void {
                if ($key === 'type' && $value === 'number') {
                    $numbers[] = $value;
                }
            });
        }
        $this->assertSame([], $numbers);
        // No example or description in the whole document carries anything that looks like a provider secret.
        $text = json_encode($api);
        foreach (['rzp_live_', 'rzp_test_', 'key_secret":"', 'whsec_'] as $secretLike) {
            $this->assertStringNotContainsString($secretLike, $text);
        }
    }

    public function test_the_simulator_exists_only_where_fake_providers_are_allowed_and_only_for_fake_attempts(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $url = '/api/v1/dev/payments/'.$payment['id'].'/simulate';

        // Somebody else's attempt, an attempt of another provider, and no session.
        [, $other] = $this->signedIn('+919811122233');
        $this->postJson($url, ['scenario' => 'SUCCESS'], $this->using($other))->assertNotFound();
        $this->attempt($payment['id'])->forceFill(['provider' => 'razorpay'])->save();
        $this->postJson($url, ['scenario' => 'SUCCESS'], $this->using($headers))->assertNotFound();
        $this->attempt($payment['id'])->forceFill(['provider' => 'fake'])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson($url, ['scenario' => 'SUCCESS'])->assertUnauthorized();
        // Where fake providers are not allowed (as in production) the fake provider is not usable at all.
        config(['payments.providers.allow_fake' => false]);
        $this->postJson($url, ['scenario' => 'SUCCESS'], $this->using($headers))->assertNotFound();

        config(['payments.providers.allow_fake' => true]);
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
        $this->assertSame([], $this->fakeProvider()->paymentsOfSession($this->attempt($payment['id'])->provider_order_reference));
    }
}
