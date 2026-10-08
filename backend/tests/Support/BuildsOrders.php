<?php

namespace Tests\Support;

use App\Auth\Principal;
use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantUser;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Orders in tests (Module 31): a ₹500 checkout paid through the FAKE provider and turned into an order by the
 * payment's verification (the queue runs synchronously in tests), a zero-total checkout the customer fulfils, and
 * the order API as the customer, the restaurant and an administrator call it.
 *
 * No test writes an order's status or amount directly: every order here came through the real path.
 */
trait BuildsOrders
{
    use BuildsPayments;

    protected Principal $actor;

    /**
     * A customer who paid: the payment is VERIFIED and the order exists.
     *
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>, 3: array<string, mixed>, 4: array<string, mixed>}
     */
    protected function paidOrder(string $phone = '+919876543210', ?string $slot = null, int $quantity = 2): array
    {
        [$customer, $headers, $checkout] = $this->readyCheckout($quantity, $slot, $phone);
        $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $paid = $this->providerPays($payment['id']);
        $verified = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->assertJsonPath('payment.status', 'VERIFIED')->json('payment');
        $this->assertNotNull($verified['fulfillment']['order_number'], 'A verified payment becomes an order');
        $order = $this->showOrder($headers, $verified['fulfillment']['order_number'])->assertOk()->json('order');

        return [$customer, $headers, $checkout, $verified, $order];
    }

    /**
     * A checkout with nothing to pay (a promotion covers everything), fulfilled by the customer.
     *
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    protected function zeroTotalOrder(string $phone = '+919876543210'): array
    {
        $this->promotion(['code' => 'FREEMEAL', 'type' => 'PERCENTAGE', 'percent_basis_points' => 10000]);
        [$customer, $headers] = $this->customerReadyForCheckout(phone: $phone);
        $draft = $this->openCheckout($headers, ['promotion_code' => 'FREEMEAL'])->assertCreated()->assertJsonPath('checkout.pricing.payment_required', false)->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->assertJsonPath('checkout.status', 'READY')->json('checkout');
        $order = $this->fulfillCheckout($headers, $ready['id'])->assertCreated()->json('order');

        return [$customer, $headers, $ready, $order];
    }

    protected function fulfillCheckout(array $headers, string $checkoutId, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/checkouts/'.$checkoutId.'/fulfill', $body, $this->using($headers));
    }

    protected function showOrder(array $headers, string $reference): TestResponse
    {
        return $this->getJson('/api/v1/customer/orders/'.$reference, $this->using($headers));
    }

    protected function listOrders(array $headers, string $query = ''): TestResponse
    {
        return $this->getJson('/api/v1/customer/orders'.($query === '' ? '' : '?'.$query), $this->using($headers));
    }

    protected function orderModel(string $reference): Order
    {
        return Order::query()->where(Str::isUuid($reference) ? 'public_id' : 'order_number', $reference)->firstOrFail();
    }

    /* ------------------------------------------------------------------ restaurant side */

    /**
     * Order staff of Burger Hub's organization, signed in.
     */
    protected function orderStaff(string $role = 'ORDER_STAFF', ?array $locations = null): RestaurantUser
    {
        return $this->asActor($this->member($this->organization, $role, $locations));
    }

    /**
     * The staff member or administrator the restaurant / admin helpers act as. Sanctum's acting-as is lost whenever
     * the guards are reset (every customer request does that), so the helpers sign the actor in again before each call.
     *
     * @template T of \App\Auth\Principal
     *
     * @param  T  $principal
     * @return T
     */
    protected function asActor(Principal $principal): Principal
    {
        $this->actor = $principal;

        return $principal;
    }

    private function act(): void
    {
        if (isset($this->actor)) {
            $this->actingAsPrincipal($this->actor);
        }
    }

    protected function restaurantOrders(string $query = ''): TestResponse
    {
        $this->act();

        return $this->getJson('/api/v1/restaurant/orders?location='.$this->burgerHub->public_id.($query === '' ? '' : '&'.$query));
    }

    protected function restaurantOrder(string $reference): TestResponse
    {
        $this->act();

        return $this->getJson('/api/v1/restaurant/orders/'.$reference);
    }

    /**
     * @param  'accept'|'reject'|'start-preparing'|'ready'  $step
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function step(string $reference, string $step, array $body = [], array $headers = []): TestResponse
    {
        $this->act();

        return $this->postJson('/api/v1/restaurant/orders/'.$reference.'/'.$step, $body, $headers);
    }

    /* ------------------------------------------------------------------ admin side */

    /**
     * @param  list<Permission>  $permissions
     */
    protected function adminFor(array $permissions = [Permission::AdminOrdersView, Permission::AdminOrdersOverride, Permission::AdminOrdersRecover, Permission::AdminSystemView]): AdminUser
    {
        return $this->asActor($this->adminWith($permissions));
    }

    protected function adminGet(string $path): TestResponse
    {
        $this->act();

        return $this->getJson('/api/v1/admin'.$path);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function adminPost(string $path, array $body = []): TestResponse
    {
        $this->act();

        return $this->postJson('/api/v1/admin'.$path, $body);
    }
}
