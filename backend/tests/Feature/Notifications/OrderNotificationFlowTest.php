<?php

namespace Tests\Feature\Notifications;

use App\Enums\SecurityEventType;
use App\Events\Order\OrderEvent;
use App\Models\CustomerDevice;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Notifications\Providers\FakePushProvider;
use App\Services\Notifications\Providers\FakeSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsNotifications;
use Tests\TestCase;

/**
 * The order lifecycle through the notification domain with the development providers (queue sync: a queued delivery is
 * sent inside the request). Nothing here touches the order: the order path is Module 31 / 32's and stays as it is.
 */
class OrderNotificationFlowTest extends TestCase
{
    use BuildsNotifications, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        FakePushProvider::forget();
    }

    public function test_an_order_creates_the_in_app_record_and_one_push_per_active_device_and_the_ready_notice_is_high_priority_pickup_update(): void
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices(['inst-phone-00000001' => 'fake-token-phone-1', 'inst-tablet-0000001' => 'fake-token-tablet-1']);

        $created = $this->notificationOf($customer, 'order.created');
        $this->assertNotNull($created);
        $this->assertSame(['ORDER_UPDATES', 'NORMAL', 'ORDER_DETAIL', $order['order_number'], true], [$created->category->value, $created->priority, $created->action_target->value, $created->action_reference, $created->transactional]);
        $this->assertStringContainsString($order['order_number'], $created->body);
        $this->assertStringContainsString('Burger Hub', $created->body);
        $this->assertEquals(['IN_APP' => ['SENT' => 1], 'PUSH' => ['SENT' => 2], 'EMAIL' => ['SKIPPED' => 1]], $this->deliverySummary($created), 'one in-app record, one push per device; the fixture customer has an unverified e-mail: skipped, never failed');

        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertOk();

        $this->assertEquals(['IN_APP' => ['SENT' => 1], 'PUSH' => ['SENT' => 2]], $this->deliverySummary($this->notificationOf($customer, 'order.accepted')));
        $preparing = $this->notificationOf($customer, 'order.preparing');
        $this->assertEquals(['IN_APP' => ['SENT' => 1]], $this->deliverySummary($preparing), 'preparing is in-app only — no push fatigue');

        $ready = $this->notificationOf($customer, 'order.ready');
        $this->assertSame(['PICKUP_UPDATES', 'HIGH', 'ORDER_TRACKING'], [$ready->category->value, $ready->priority, $ready->action_target->value]);
        $summary = $this->deliverySummary($ready);
        $this->assertSame(['SENT' => 2], $summary['PUSH']);
        $this->assertSame(['SENT' => 1], $summary['SMS'], 'the SMS abstraction delivered through the development provider (PICKUP_UPDATES SMS default on, phone verified)');
        $this->assertSame(1, count(app(FakeSmsProvider::class)->sent));
        $this->assertStringNotContainsString('refund', strtolower($ready->body));

        $inbox = $this->inbox($headers)->assertOk()->json();
        $this->assertSame(4, $inbox['unread_count']);
        $this->assertSame(['order.ready', 'order.preparing', 'order.accepted', 'order.created'], array_column($inbox['data'], 'type'), 'newest first');
        $this->assertArrayNotHasKey('deliveries', $inbox['data'][0]);
    }

    public function test_the_push_payload_carries_no_secret_and_no_personal_data(): void
    {
        [$customer, $headers, $order] = $this->readyOrderWithDevices(['inst-rahul-phone-1' => 'fake-token-rahul-phone-1']);
        $credential = $this->credentialOf($this->using($headers), $order['order_number']);

        $messages = FakePushProvider::lastMessages();
        $this->assertNotEmpty($messages);
        $joined = json_encode($messages);
        foreach ([$credential['qr_payload'], $credential['code'], $customer->phone_e164, '98765', '@', 'FOTG1.'] as $never) {
            $this->assertStringNotContainsString($never, $joined, 'push payloads never carry: '.$never);
        }
        $ready = collect($messages)->last(fn (array $m): bool => ($m['message']['data']['type'] ?? '') === 'order.ready');
        $this->assertNotNull($ready);
        $this->assertSame(['HIGH', 'pickup', 'PRIVATE'], [$ready['message']['android']['priority'], $ready['message']['android']['notification']['channel_id'], $ready['message']['android']['notification']['visibility']]);
        $this->assertSame(['ORDER_TRACKING', $order['order_number'], 'PICKUP_UPDATES'], [$ready['message']['data']['target'], $ready['message']['data']['reference'], $ready['message']['data']['category']]);
        $this->assertSame(array_keys($ready['message']['data']), array_values(array_filter(array_keys($ready['message']['data']), fn (string $k): bool => in_array($k, ['type', 'notification_id', 'category', 'target', 'reference', 'subject_id', 'channel_id', 'created_at'], true))), 'only the allow-listed keys');
        $this->assertLessThan(4096, $ready['bytes']);
        $this->assertArrayNotHasKey('token', $ready['message']);
    }

    public function test_the_same_domain_event_twice_makes_one_notification_and_a_rejected_paid_order_says_the_refund_is_pending(): void
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices(['inst-rahul-phone-1' => 'fake-token-rahul-phone-1']);
        $model = $this->orderModel($order['order_number']);

        $before = CustomerNotification::query()->count();
        event(new OrderEvent(OrderEvent::ORDER_CREATED, $model, ['sequence' => 1]));
        event(new OrderEvent(OrderEvent::ORDER_CREATED, $model, ['sequence' => 1]));
        event(new OrderEvent(OrderEvent::ORDER_CREATED, $model, ['sequence' => 1]));
        $this->assertSame($before + 1, CustomerNotification::query()->count(), 'the same domain event three times: one notification (the dedupe key is order + sequence + type)');

        $this->orderStaff();
        $this->step($order['order_number'], 'reject', ['reason' => 'CLOSING'])->assertOk();
        $rejected = $this->notificationOf($customer, 'order.rejected');
        $this->assertNotNull($rejected);
        // Module 35: a refund is OWED at this moment — nothing was sent to the provider yet, so the notice no longer says "is being processed".
        $this->assertStringContainsString('A refund is due for your payment; we are arranging it.', $rejected->body);
        $this->assertStringNotContainsString('being processed', $rejected->body);
        $this->assertStringNotContainsString('refund completed', strtolower($rejected->body));
        $this->assertSame('HIGH', $rejected->priority);
        $refund = $this->notificationOf($customer, 'refund.pending');
        $this->assertNotNull($refund, 'the REFUND_REQUIRED event becomes a PAYMENT_UPDATES notice');
        $this->assertSame(['PAYMENT_UPDATES', 'PAYMENT_STATUS'], [$refund->category->value, $refund->action_target->value]);
    }

    public function test_a_device_the_provider_reports_unregistered_is_deactivated_while_the_other_device_and_the_notification_stand(): void
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices(['inst-good-00000001' => 'fake-token-good-1', 'inst-dead-00000001' => 'fake-token-unregistered-1']);

        $created = $this->notificationOf($customer, 'order.created');
        $pushes = $this->pushDeliveries($created);
        $this->assertCount(2, $pushes);
        $byToken = $pushes->keyBy(fn (NotificationDelivery $d): string => (string) $d->device->installation_id);
        $this->assertSame('SENT', $byToken['inst-good-00000001']->status->value);
        $this->assertSame(['INVALID_DESTINATION', 'UNREGISTERED', 'INVALID_DESTINATION'], [$byToken['inst-dead-00000001']->status->value, $byToken['inst-dead-00000001']->last_error_code, $byToken['inst-dead-00000001']->last_error_category->value]);
        $this->assertSame('INVALID_TOKEN', CustomerDevice::query()->where('installation_id', 'inst-dead-00000001')->first()->revoke_reason->value);
        $this->assertNull(CustomerDevice::query()->where('installation_id', 'inst-good-00000001')->first()->revoked_at);
        $this->assertSame(1, $byToken['inst-dead-00000001']->attempts()->count(), 'no retry of a dead token');

        // The next notice targets the live device only.
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->assertCount(1, $this->pushDeliveries($this->notificationOf($customer, 'order.accepted')));
    }

    public function test_preferences_decide_optional_channels_in_app_stays_and_a_denied_os_permission_is_not_targeted(): void
    {
        [$customer, $headers, $checkout] = $this->readyCheckout();
        $device = $this->registerDevice($headers)->assertCreated()->json('device');
        $this->withHeaders($this->using($headers))->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'ORDER_UPDATES', 'channel' => 'PUSH', 'enabled' => false]]])->assertOk();
        $payment = $this->startPayment($this->using($headers), $checkout)->assertCreated()->json('payment');
        $verified = $this->completePayment($this->using($headers), $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->json('payment');
        $order = $this->showOrder($this->using($headers), $verified['fulfillment']['order_number'])->assertOk()->json('order');

        $created = $this->notificationOf($customer, 'order.created');
        $this->assertEquals(['IN_APP' => ['SENT' => 1], 'PUSH' => ['SKIPPED' => 1], 'EMAIL' => ['SKIPPED' => 1]], $this->deliverySummary($created));
        $this->assertSame('SKIP_USER_PREFERENCE', NotificationDelivery::query()->where('notification_id', $created->getKey())->where('channel', 'PUSH')->first()->policy_result->value);

        // PICKUP_UPDATES push is still on — but the OS permission was denied on the phone: no push, the in-app record remains.
        $this->withHeaders($this->using($headers))->patchJson('/api/v1/customer/devices/'.$device['id'], ['notifications_permission_state' => 'DENIED'])->assertOk();
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertOk();
        $ready = $this->notificationOf($customer, 'order.ready');
        $this->assertSame(['SENT' => 1], $this->deliverySummary($ready)['IN_APP']);
        $this->assertSame(['SKIPPED' => 1], $this->deliverySummary($ready)['PUSH']);
        $this->assertSame('SKIP_NO_DESTINATION', NotificationDelivery::query()->where('notification_id', $ready->getKey())->where('channel', 'PUSH')->first()->policy_result->value);
    }

    public function test_channels_disabled_on_the_server_and_missing_destinations_are_recorded_as_skips_never_as_failures(): void
    {
        config(['notifications.channels.SMS.driver' => 'off']);
        [$customer, $headers, $checkout] = $this->readyCheckout();
        $customer->forceFill(['email' => 'rahul@example.com'])->save();
        $payment = $this->startPayment($this->using($headers), $checkout)->assertCreated()->json('payment');
        $verified = $this->completePayment($this->using($headers), $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->json('payment');
        $order = $this->showOrder($this->using($headers), $verified['fulfillment']['order_number'])->assertOk()->json('order');
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertOk();

        $ready = $this->notificationOf($customer, 'order.ready');
        $summary = $this->deliverySummary($ready);
        $this->assertSame(['SKIPPED' => 1], $summary['PUSH'], 'no device registered → no destination');
        $this->assertSame(['SKIPPED' => 1], $summary['SMS'], 'the SMS channel is off on this server');
        $this->assertSame('SKIP_CHANNEL_DISABLED', NotificationDelivery::query()->where('notification_id', $ready->getKey())->where('channel', 'SMS')->first()->policy_result->value);
        $created = $this->notificationOf($customer, 'order.created');
        $this->assertSame(['SKIPPED' => 1], $this->deliverySummary($created)['EMAIL'], 'an unverified address is no destination');
        $this->assertSame('SKIP_NO_DESTINATION', NotificationDelivery::query()->where('notification_id', $created->getKey())->where('channel', 'EMAIL')->first()->policy_result->value);
        $this->assertSame(0, NotificationDelivery::query()->where('status', 'FAILED')->count());
        $this->assertSame('READY_FOR_PICKUP', $this->showOrder($this->using($headers), $order['order_number'])->json('order.status'), 'the order never noticed any of this');
    }

    public function test_the_inbox_is_the_customers_own_read_is_idempotent_and_another_customer_cannot_touch_it(): void
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices([]);
        [, $ashaHeaders] = $this->otherCustomer();
        $created = $this->notificationOf($customer, 'order.created');

        $this->assertSame(1, $this->withHeaders($this->using($headers))->getJson('/api/v1/customer/notifications/unread-count')->assertOk()->json('unread_count'));
        $this->assertSame(0, $this->withHeaders($this->using($ashaHeaders))->getJson('/api/v1/customer/notifications/unread-count')->assertOk()->json('unread_count'));
        $this->withHeaders($this->using($ashaHeaders))->postJson('/api/v1/customer/notifications/'.$created->public_id.'/read')->assertNotFound()->assertJsonPath('error.code', 'notification_not_found');
        $this->assertSame([], $this->withHeaders($this->using($ashaHeaders))->getJson('/api/v1/customer/notifications')->assertOk()->json('data'));

        $read = $this->withHeaders($this->using($headers))->postJson('/api/v1/customer/notifications/'.$created->public_id.'/read')->assertOk()->json();
        $this->assertTrue($read['notification']['read']);
        $this->assertSame(0, $read['unread_count']);
        $first = $created->refresh()->read_at;
        $this->travel(5)->minutes();
        $this->withHeaders($this->using($headers))->postJson('/api/v1/customer/notifications/'.$created->public_id.'/read')->assertOk();
        $this->assertTrue($first->equalTo($created->refresh()->read_at), 'the first read wins');

        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->assertSame(2, $this->withHeaders($this->using($headers))->getJson('/api/v1/customer/notifications?unread=1')->assertOk()->json('unread_count'));
        $this->assertSame(2, $this->withHeaders($this->using($headers))->postJson('/api/v1/customer/notifications/read-all')->assertOk()->json('marked'));
        $this->assertSame(0, CustomerNotification::query()->where('customer_id', $customer->getKey())->whereNull('read_at')->count());
        $this->assertSame(3, CustomerNotification::query()->where('customer_id', $customer->getKey())->count(), 'read-all marks, never deletes');
        $this->assertSame(1, $this->withHeaders($this->using($headers))->getJson('/api/v1/customer/notifications?category=ORDER_UPDATES&per_page=1')->assertOk()->json('meta.per_page'));
    }

    public function test_a_phone_change_is_a_mandatory_security_notice_that_a_preference_cannot_switch_off(): void
    {
        [$customer, $headers] = $this->customerReadyForCheckout();
        $this->registerDevice($headers)->assertCreated();
        $this->withHeaders($this->using($headers))->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'ACCOUNT_SECURITY', 'channel' => 'PUSH', 'enabled' => false]]])->assertOk();

        app(SecurityEventRecorder::class)->record(SecurityEventType::PhoneChanged, $customer, ['from' => 'masked', 'to' => 'masked']);

        $notice = $this->notificationOf($customer, 'account.phone_changed');
        $this->assertNotNull($notice);
        $this->assertSame(['ACCOUNT_SECURITY', 'HIGH', 'ACCOUNT_SECURITY'], [$notice->category->value, $notice->priority, $notice->action_target->value]);
        $this->assertSame(['SENT' => 1], $this->deliverySummary($notice)['PUSH'], 'mandatory: the customer preference does not apply');
        $this->assertSame('MANDATORY', NotificationDelivery::query()->where('notification_id', $notice->getKey())->where('channel', 'PUSH')->first()->policy_result->value);
        $this->assertStringNotContainsString('9876', $notice->body.json_encode(FakePushProvider::lastMessages()));
    }
}
