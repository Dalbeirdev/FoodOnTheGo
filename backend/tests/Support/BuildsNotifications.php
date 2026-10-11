<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Testing\TestResponse;

/**
 * Module 33 helpers on top of the order world: devices, the inbox, deliveries. Tokens used here are invented strings;
 * the fake push provider reads the simulated outcome from them (see config notifications.fake).
 */
trait BuildsNotifications
{
    use BuildsOrders;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    protected function registerDevice(array $headers, string $installation = 'inst-rahul-phone-1', string $token = 'fake-token-rahul-phone-1', array $extra = []): TestResponse
    {
        return $this->withHeaders($this->using($headers))->putJson('/api/v1/customer/devices/current', $extra + [
            'installation_id' => $installation, 'platform' => 'ANDROID', 'push_provider' => 'FAKE', 'push_token' => $token,
            'app_version' => '0.1.0+37', 'device_locale' => 'en-IN', 'timezone' => 'Asia/Kolkata', 'notifications_permission_state' => 'GRANTED',
        ]);
    }

    /**
     * A paid order whose customer registered devices BEFORE paying (so the order.created notice has push targets).
     *
     * @param  array<string, string>  $devices  installation id => token
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>} customer, headers, order
     */
    protected function paidOrderWithDevices(array $devices, string $phone = '+919876543210'): array
    {
        [$customer, $headers, $checkout] = $this->readyCheckout(2, null, $phone);
        foreach ($devices as $installation => $token) {
            $this->registerDevice($headers, $installation, $token)->assertCreated();
        }
        $payment = $this->startPayment($this->using($headers), $checkout)->assertCreated()->json('payment');
        $paid = $this->providerPays($payment['id']);
        $verified = $this->completePayment($this->using($headers), $payment['id'], $this->proofOf($payment['id'], $paid))->assertOk()->json('payment');
        $order = $this->showOrder($this->using($headers), $verified['fulfillment']['order_number'])->assertOk()->json('order');

        return [$customer, $headers, $order];
    }

    /**
     * @param  array<string, string>  $devices
     * @return array{0: Customer, 1: array<string, string>, 2: array<string, mixed>}
     */
    protected function readyOrderWithDevices(array $devices, string $phone = '+919876543210'): array
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices($devices, $phone);
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertOk();

        return [$customer, $headers, $this->showOrder($this->using($headers), $order['order_number'])->assertOk()->json('order')];
    }

    /**
     * @return array{0: Customer, 1: array<string, string>}
     */
    protected function otherCustomer(string $phone = '+919876543212'): array
    {
        $customer = Customer::query()->where('phone_e164', $phone)->first() ?? $this->customer($this->market, ['phone_e164' => $phone, 'phone_verified_at' => now()]);

        return [$customer, $this->sessionOf($customer, 'other')];
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function inbox(array $headers, string $query = ''): TestResponse
    {
        return $this->withHeaders($this->using($headers))->getJson('/api/v1/customer/notifications'.$query);
    }

    protected function notificationOf(Customer $customer, string $type): ?CustomerNotification
    {
        return CustomerNotification::query()->where('customer_id', $customer->getKey())->where('event_type', $type)->with('deliveries.device')->first();
    }

    /**
     * @return array<string, array<string, int>> channel => status => count
     */
    protected function deliverySummary(CustomerNotification $notification): array
    {
        $out = [];
        foreach ($notification->deliveries()->get() as $d) {
            $out[$d->channel->value][$d->status->value] = ($out[$d->channel->value][$d->status->value] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    protected function pushDeliveries(CustomerNotification $notification): Collection
    {
        return NotificationDelivery::query()->where('notification_id', $notification->getKey())->where('channel', 'PUSH')->orderBy('id')->with(['device', 'attempts'])->get();
    }
}
