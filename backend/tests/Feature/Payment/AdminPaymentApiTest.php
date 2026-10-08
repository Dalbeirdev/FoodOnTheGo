<?php

namespace Tests\Feature\Payment;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Enums\ProviderFailure;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\PaymentAttempt;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * Payments for administrators (Module 30): the real attempts, their timelines and the provider's events — readable
 * with the payments permission, market by market — and one action, "ask the provider again", that changes only what
 * the provider confirms. Nobody can mark a payment as paid.
 */
class AdminPaymentApiTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    private AdminUser $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->seed(RoleSeeder::class);
        $this->super = $this->adminWithRole('SUPER_ADMIN');
    }

    private function adminWithRole(string $code, ?Market $scope = null): AdminUser
    {
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail(), $scope === null ? null : Scope::market($scope->public_id));

        return $admin;
    }

    /**
     * Three payments of three customers: one verified by card, one cancelled, one still open.
     *
     * @return array{verified: array<string, mixed>, cancelled: array<string, mixed>, open: array<string, mixed>}
     */
    private function threePayments(): array
    {
        [, $asha, , $verified] = $this->openPayment('+919876543210');
        $this->completePayment($asha, $verified['id'], $this->proofOf($verified['id'], $this->providerPays($verified['id'], 'captured', ['method' => 'CARD'])))->assertOk();
        $this->travel(1)->seconds();
        [, $ravi, , $cancelled] = $this->openPayment('+919811122233');
        $this->completePayment($ravi, $cancelled['id'], ['outcome' => 'cancelled'])->assertOk();
        $this->travel(1)->seconds();
        [, , , $open] = $this->openPayment('+919822233344');

        return ['verified' => $verified, 'cancelled' => $cancelled, 'open' => $open];
    }

    public function test_the_list_shows_the_real_payment_attempts_with_what_an_operator_needs_and_no_more(): void
    {
        $payments = $this->threePayments();

        $this->actingAsPrincipal($this->super);
        $list = $this->getJson('/api/v1/admin/payments')->assertOk()->json();

        $this->assertSame(3, $list['meta']['total']);
        $this->assertSame([$payments['open']['id'], $payments['cancelled']['id'], $payments['verified']['id']], array_column($list['data'], 'id'));   // newest first
        $row = collect($list['data'])->firstWhere('id', $payments['verified']['id']);
        $this->assertSame([
            'status' => 'VERIFIED', 'fulfillment_status' => 'ORDER_CREATED', 'needs_attention' => false, 'verified_without_order' => false,   // Module 31: the verified payment became an order
            'amount' => ['amount' => 50000, 'currency' => 'INR'], 'provider' => 'fake', 'provider_mode' => 'FAKE', 'method_type' => 'CARD', 'failure_category' => null,
        ], array_intersect_key($row, array_flip(['status', 'fulfillment_status', 'needs_attention', 'verified_without_order', 'amount', 'provider', 'provider_mode', 'method_type', 'failure_category'])));
        $this->assertSame('Burger Hub', $row['restaurant']['name']);
        $this->assertSame($this->market->slug, $row['market']);
        $this->assertMatchesRegularExpression('/\APAY-[0-9A-F]{10}\z/', $row['support_reference']);
        // A customer is a reference and a masked number — the payment list is not a customer directory.
        $this->assertSame('+91******3210', $row['customer']['phone_masked']);
        $json = json_encode($list);
        foreach (['+919876543210', 'provider_order_reference', 'provider_payment_reference', 'signature', 'secret', 'receipt', 'email'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
    }

    public function test_the_list_can_be_filtered_searched_sorted_and_paged(): void
    {
        $payments = $this->threePayments();
        $this->actingAsPrincipal($this->super);
        $ids = fn (string $query): array => array_column($this->getJson('/api/v1/admin/payments?'.$query)->assertOk()->json('data'), 'id');

        $this->assertSame([$payments['verified']['id']], $ids('filter[status]=VERIFIED'));
        $this->assertSame([$payments['cancelled']['id']], $ids('filter[status]=cancelled'));
        $this->assertSame([$payments['verified']['id']], $ids('filter[method]=card'));
        $this->assertSame([$payments['verified']['id']], $ids('filter[fulfillment]=ORDER_CREATED'));
        $this->assertSame([], $ids('filter[fulfillment]=ORDER_PENDING'));
        $this->assertCount(3, $ids('filter[provider]=fake&filter[currency]=INR'));
        $this->assertSame([], $ids('filter[provider]=razorpay'));
        $this->assertCount(3, $ids('filter[restaurant]='.$this->burgerHub->public_id));
        $this->assertSame([], $ids('filter[restaurant]=00000000-0000-4000-8000-000000000000'));
        $this->assertCount(3, $ids('filter[from]='.now()->utc()->toDateString().'&filter[to]='.now()->utc()->toDateString()));
        $this->assertSame([], $ids('filter[from]='.now()->utc()->addDay()->toDateString()));
        $this->assertSame([], $ids('filter[attention]=true'));
        // Search by references only: the payment, its support reference, the checkout it paid, a provider reference.
        $verified = $this->attempt($payments['verified']['id']);
        foreach ([$verified->public_id, $verified->supportReference(), $verified->checkout_public_id, $verified->provider_order_reference, $verified->provider_payment_reference] as $reference) {
            $this->assertSame([$payments['verified']['id']], $ids('q='.urlencode((string) $reference)), (string) $reference);
        }
        $this->assertSame([], $ids('q=Burger'));
        $this->assertSame([], $ids('q=%2B919876543210'));
        // Sorted and paged like every other admin list.
        $this->assertSame([$payments['verified']['id'], $payments['cancelled']['id'], $payments['open']['id']], $ids('sort=created_at'));
        $page = $this->getJson('/api/v1/admin/payments?page[size]=2&page[number]=2&sort=created_at')->assertOk()->json();
        $this->assertSame([[$payments['open']['id']], 3, 2, 2], [array_column($page['data'], 'id'), $page['meta']['total'], $page['meta']['current_page'], $page['meta']['last_page']]);
        $this->getJson('/api/v1/admin/payments?filter[amount]=1')->assertStatus(422);
        $this->getJson('/api/v1/admin/payments?filter[from]=yesterday')->assertStatus(422);
    }

    public function test_the_detail_shows_the_timeline_the_providers_events_and_the_references_and_no_secret(): void
    {
        [, $headers, $checkout, $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id'], 'captured', ['method' => 'UPI']);
        $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk();
        $this->providerSends('payment.captured', $paid)->assertOk();

        $this->actingAsPrincipal($this->super);
        $detail = $this->getJson('/api/v1/admin/payments/'.$payment['id'])->assertOk()->json('payment');

        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['order' => $attempt->provider_order_reference, 'payment' => $paid->reference, 'receipt' => $attempt->provider_receipt], $detail['provider_references']);
        $this->assertSame(['client_outcome' => 'success', 'signature_failures' => 0], array_intersect_key($detail['verification'], array_flip(['client_outcome', 'signature_failures'])));
        $this->assertNotNull($detail['verification']['signature_verified_at']);
        $this->assertSame([$checkout['id'], 'CONVERTED'], [$detail['checkout']['id'], $detail['checkout']['status']]);   // Module 31: the paid checkout became an order
        $this->assertSame(['vpa' => 'su***@fake'], $detail['method_summary']);
        // The timeline tells the story in order, with who said what.
        $this->assertSame(
            [['attempt_created', 'SYSTEM'], ['provider_ready', 'PROVIDER'], ['client_callback', 'CLIENT'], ['processing', 'CLIENT'], ['verified', 'CLIENT'], ['fulfillment_order_created', 'JOB'], ['webhook_payment_captured', 'WEBHOOK']],   // Module 31: the order was created from the verified payment
            array_map(fn (array $line): array => [$line['type'], $line['source']], $detail['timeline']),
        );
        $this->assertSame(['PROCESSING', 'VERIFIED'], [$detail['timeline'][4]['from'], $detail['timeline'][4]['to']]);
        $this->assertSame([['payment.captured', 'PAYMENT_CAPTURED', 'PROCESSED', 'VERIFIED', 1]], array_map(fn (array $e): array => [$e['type'], $e['normalized_type'], $e['processing_status'], $e['outcome'], $e['deliveries']], $detail['webhook_events']));
        // What cannot be done is said plainly.
        $this->assertSame(['mark_paid' => false, 'refund' => 'NOT_IMPLEMENTED'], $detail['actions']);
        $json = json_encode($detail);
        foreach (['signature":"', 'secret', 'fake-provider', 'key_id', '+919876543210', 'payload'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
        $this->getJson('/api/v1/admin/payments/00000000-0000-4000-8000-000000000000')->assertNotFound()->assertJsonPath('error.code', 'payment_not_found');
    }

    public function test_only_administrators_with_the_payments_permission_see_payments(): void
    {
        $payments = $this->threePayments();
        $one = '/api/v1/admin/payments/'.$payments['verified']['id'];

        // Support and finance may look; onboarding and moderation may not.
        foreach (['SUPER_ADMIN', 'OPERATIONS_ADMIN', 'SUPPORT_ADMIN', 'FINANCE_ADMIN', 'ANALYST'] as $role) {
            $this->actingAsPrincipal($this->adminWithRole($role));
            $this->getJson('/api/v1/admin/payments')->assertOk()->assertJsonPath('meta.total', 3);
            $this->getJson($one)->assertOk();
        }
        foreach (['RESTAURANT_ONBOARDING', 'MODERATION_ADMIN'] as $role) {
            $this->actingAsPrincipal($this->adminWithRole($role));
            $this->getJson('/api/v1/admin/payments')->assertForbidden();
            $this->getJson($one)->assertForbidden();
        }
        // Nobody else has these endpoints at all: not a customer, not restaurant staff, not a guest.
        [, $customer] = $this->signedIn('+919833344455');
        $this->getJson('/api/v1/admin/payments', $this->using($customer))->assertUnauthorized();
        $this->actingAsPrincipal($this->staff());
        $this->getJson('/api/v1/admin/payments')->assertUnauthorized();
        $this->getJson($one)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/payments')->assertUnauthorized();
    }

    public function test_a_permission_held_for_one_market_covers_that_markets_payments_only(): void
    {
        $payments = $this->threePayments();
        $elsewhere = Market::factory()->create(['slug' => 'elsewhere', 'status' => 'ACTIVE']);

        $this->actingAsPrincipal($this->adminWith([Permission::AdminPaymentsView], $this->market));
        $this->getJson('/api/v1/admin/payments')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/admin/payments/'.$payments['open']['id'])->assertOk();

        $this->actingAsPrincipal($this->adminWith([Permission::AdminPaymentsView], $elsewhere));
        $this->getJson('/api/v1/admin/payments')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/payments/'.$payments['open']['id'])->assertForbidden();
    }

    public function test_asking_the_provider_again_needs_its_own_permission_and_a_reason_and_is_audited(): void
    {
        [, , , $payment] = $this->openPayment();
        $this->providerPays($payment['id']);   // paid; nobody has told FoodOnTheGo
        $url = '/api/v1/admin/payments/'.$payment['id'].'/reconcile';

        // Seeing payments is not enough: support may look, but not act.
        $this->actingAsPrincipal($this->adminWithRole('SUPPORT_ADMIN'));
        $this->postJson($url, ['reason' => 'Customer called'])->assertForbidden();
        $this->actingAsPrincipal($this->adminWithRole('OPERATIONS_ADMIN'));
        $this->postJson($url, ['reason' => 'Customer called'])->assertForbidden();
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);

        $finance = $this->adminWithRole('FINANCE_ADMIN');
        $this->actingAsPrincipal($finance);
        $this->postJson($url, [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson($url, ['reason' => 'ok'])->assertStatus(422);
        $detail = $this->postJson($url, ['reason' => 'Customer says the money left their account'])->assertOk()->json('payment');

        $this->assertSame('VERIFIED', $detail['status']);
        $this->assertSame(['verified', 'ADMIN'], [collect($detail['timeline'])->last(fn (array $l): bool => $l['to'] !== null)['type'], collect($detail['timeline'])->last(fn (array $l): bool => $l['to'] !== null)['source']]);
        $audit = AuditEvent::query()->where('action', 'payment.reconciled')->sole();
        $this->assertSame(['payment_attempts', $payment['id'], 'Customer says the money left their account'], [$audit->target_type, $audit->target_public_id, $audit->reason]);
        $this->assertEquals(['from' => 'PROVIDER_READY', 'to' => 'VERIFIED'], $audit->changes['status']);
        $this->assertSame($finance->public_id, $audit->actor_public_id);
    }

    public function test_nobody_can_mark_a_payment_as_paid_or_edit_what_it_is(): void
    {
        [, , , $payment] = $this->openPayment();   // not paid at the provider
        $url = '/api/v1/admin/payments/'.$payment['id'];
        $this->actingAsPrincipal($this->super);

        // "Ask the provider again" changes nothing the provider does not confirm …
        $this->postJson($url.'/reconcile', ['reason' => 'Please mark this as paid'])->assertOk()->assertJsonPath('payment.status', 'PROVIDER_READY');
        // … cannot carry a status, an amount or a reference …
        foreach ([['status' => 'VERIFIED'], ['verified' => true], ['paid' => true], ['amount_minor' => 1], ['provider_payment_reference' => 'pay_x'], ['fulfillment_status' => 'ORDER_CREATED']] as $attempted) {
            $this->postJson($url.'/reconcile', ['reason' => 'Manual correction', ...$attempted])->assertStatus(422);
        }
        // … and there is no other way in: no PATCH, PUT or DELETE, no "mark paid", "capture" or "refund" route.
        foreach (['patch', 'put', 'delete'] as $method) {
            $this->json($method, $url, ['status' => 'VERIFIED'])->assertStatus(405);
        }
        foreach (['mark-paid', 'verify', 'capture', 'refund', 'status'] as $action) {
            $this->postJson($url.'/'.$action, ['reason' => 'Manual correction'])->assertNotFound();
        }
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri())->filter(fn (string $r): bool => str_contains($r, 'admin/payments'))->values()->all();
        $this->assertSame(['GET|HEAD api/v1/admin/payments', 'GET|HEAD api/v1/admin/payments/{payment}', 'POST api/v1/admin/payments/{payment}/reconcile'], $routes);

        $attempt = $this->attempt($payment['id']);
        $this->assertSame(['PROVIDER_READY', null, 50000], [$attempt->status->value, $attempt->verified_at, $attempt->amount_minor]);
    }

    public function test_money_that_needs_a_person_and_paid_checkouts_without_an_order_stand_out(): void
    {
        // A verified payment whose restaurant stopped taking orders, a mismatch, and an ordinary cancelled one.
        [, $asha, , $recovery] = $this->openPayment('+919876543210');
        $this->burgerHub->forceFill(['accepting_orders' => false])->save();
        $this->completePayment($asha, $recovery['id'], $this->proofOf($recovery['id'], $this->providerPays($recovery['id'])))->assertOk()->assertJsonPath('payment.fulfillment.status', 'RECOVERY_REQUIRED');
        $this->burgerHub->forceFill(['accepting_orders' => true])->save();
        [, $ravi, , $mismatch] = $this->openPayment('+919811122233');
        $this->completePayment($ravi, $mismatch['id'], $this->proofOf($mismatch['id'], $this->providerPays($mismatch['id'], 'captured', ['amount_minor' => 100])))->assertOk()->assertJsonPath('payment.status', 'REVIEW_REQUIRED');
        [, $meera, , $cancelled] = $this->openPayment('+919822233344');
        $this->completePayment($meera, $cancelled['id'], ['outcome' => 'cancelled'])->assertOk();

        $this->actingAsPrincipal($this->super);
        $attention = $this->getJson('/api/v1/admin/payments?filter[attention]=true&sort=created_at')->assertOk()->json('data');
        $this->assertSame([$recovery['id'], $mismatch['id']], array_column($attention, 'id'));
        $this->assertSame([[true, true, 'RESTAURANT_NOT_ACCEPTING'], [true, false, null]], array_map(fn (array $r): array => [$r['needs_attention'], $r['verified_without_order'], $r['fulfillment_reason']], $attention));
        $this->assertSame('PAYMENT_AMOUNT_MISMATCH', $attention[1]['status_reason']);

        $numbers = $this->getJson('/api/v1/admin/system/payments')->assertOk()->json();
        $this->assertSame(['order_pending' => 0, 'recovery_required' => 1, 'order_created' => 0], $numbers['verified_payments']);
        $this->assertSame(1, $numbers['needs_review']);
        $this->assertSame([1, 1, 1], [$numbers['attempts']['verified'], $numbers['attempts']['review_required'], $numbers['attempts']['cancelled']]);
    }

    public function test_the_system_numbers_are_counts_and_states_and_carry_no_value_of_any_key(): void
    {
        $this->threePayments();
        config(['services.razorpay.key_id' => 'rzp_test_ShouldNeverShow001', 'services.razorpay.key_secret' => 'should-never-show-secret', 'services.razorpay.webhook_secret' => 'should-never-show-webhook']);

        $this->actingAsPrincipal($this->adminWithRole('ANALYST'));   // system view without any financial action
        $numbers = $this->getJson('/api/v1/admin/system/payments')->assertOk()->json();

        $this->assertSame(['driver' => 'fake', 'mode' => 'FAKE', 'configured' => true, 'missing' => [], 'fake' => true, 'fake_allowed' => true, 'live_allowed' => false], array_intersect_key($numbers['provider'], array_flip(['driver', 'mode', 'configured', 'missing', 'fake', 'fake_allowed', 'live_allowed'])));
        $this->assertSame('OPERATIONAL', $numbers['provider']['health']['state']);
        $this->assertGreaterThan(0, $numbers['provider']['health']['calls']);
        $this->assertSame([3, 3, 1, 1], [$numbers['events']['attempt_created'], $numbers['events']['provider_ready'], $numbers['events']['verified'], $numbers['events']['cancelled']]);
        $this->assertEquals(['verified_of_settled' => 0.5, 'failed_of_settled' => 0], $numbers['rates']);   // a cancellation is not a failure
        $this->assertSame(['CARD' => 1], $numbers['verified_by_method']);
        $this->assertSame(['IMPLEMENTED', 'NOT_IMPLEMENTED'], [$numbers['order_creation'], $numbers['refunds']]);
        $json = json_encode($numbers);
        foreach (['ShouldNeverShow', 'should-never-show', 'fake-provider', '+91', 'fakepay_', 'fakeorder_', 'pa_'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }

        // With Razorpay named but not configured the page says what is missing — by name.
        config(['services.payment.driver' => 'razorpay', 'services.razorpay.key_secret' => null, 'services.razorpay.webhook_secret' => null]);
        $provider = $this->getJson('/api/v1/admin/system/payments')->assertOk()->json('provider');
        $this->assertSame(['razorpay', false, ['RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET']], [$provider['driver'], $provider['configured'], $provider['missing']]);
        // The system page needs its permission.
        $this->actingAsPrincipal($this->adminWithRole('FINANCE_ADMIN'));
        $this->getJson('/api/v1/admin/system/payments')->assertForbidden();
    }

    public function test_the_list_costs_the_same_queries_for_three_payments_and_for_twenty(): void
    {
        $count = function (): int {
            $this->actingAsPrincipal($this->super);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/payments?page[size]=50')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $this->threePayments();
        $count();   // the first request of an administrator also reads their permissions once
        $few = $count();

        $template = PaymentAttempt::query()->where('status', 'CANCELLED')->firstOrFail();
        foreach (range(1, 17) as $i) {
            $copy = new PaymentAttempt;
            $copy->forceFill([...collect($template->getAttributes())->except(['id', 'public_id', 'created_at', 'updated_at'])->all(), 'checkout_public_id' => (string) Str::uuid(), 'provider_receipt' => 'pa_copy_'.$i, 'provider_order_reference' => 'fakeorder_copy_'.$i])->save();
        }
        $this->assertSame(20, PaymentAttempt::query()->count());

        $this->assertSame($few, $count(), 'The number of queries must not grow with the number of payments.');
    }

    public function test_a_provider_outage_is_visible_as_health_not_as_failed_payments(): void
    {
        [, $headers, , $payment] = $this->openPayment();
        $proof = $this->proofOf($payment['id'], $this->providerPays($payment['id']));
        $this->fakeProvider()->failNext('payments', ProviderFailure::Unavailable, 50);
        $this->completePayment($headers, $payment['id'], $proof)->assertOk()->assertJsonPath('payment.status', 'UNKNOWN');

        $this->actingAsPrincipal($this->super);
        $numbers = $this->getJson('/api/v1/admin/system/payments')->assertOk()->json();

        $this->assertContains($numbers['provider']['health']['state'], ['DEGRADED', 'OUTAGE']);
        $this->assertGreaterThan(0, $numbers['provider']['health']['failures']);
        $this->assertSame([1, 0], [$numbers['attempts']['unknown'], $numbers['attempts']['failed']]);
    }
}
