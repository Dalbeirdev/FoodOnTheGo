<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationErrorCategory;
use App\Enums\Permission;
use App\Jobs\Notifications\SendNotificationDeliveryJob;
use App\Models\AuditEvent;
use App\Models\CustomerDevice;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsNotifications;
use Tests\TestCase;

class DeliveryRetryAndAdminTest extends TestCase
{
    use BuildsNotifications, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    /** @return array{0: CustomerNotification, 1: NotificationDelivery} */
    private function queuedPush(string $token): array
    {
        Bus::fake([SendNotificationDeliveryJob::class]);
        $this->paidOrderWithDevices(['inst-retry-00000001' => $token]);
        $notification = CustomerNotification::query()->where('event_type', 'order.created')->firstOrFail();
        $delivery = NotificationDelivery::query()->where('notification_id', $notification->getKey())->where('channel', 'PUSH')->firstOrFail();
        $this->assertSame('QUEUED', $delivery->status->value);
        Bus::assertDispatched(SendNotificationDeliveryJob::class, fn (SendNotificationDeliveryJob $job): bool => $job->deliveryId === $delivery->getKey());

        return [$notification, $delivery];
    }

    public function test_a_transient_provider_failure_schedules_a_bounded_backoff_and_the_attempt_cap_ends_in_failed(): void
    {
        config(['notifications.retry.max_attempts' => 3, 'notifications.retry.base_seconds' => 30]);
        [, $delivery] = $this->queuedPush('fake-token-transient-1');
        $service = app(NotificationDeliveryService::class);

        $service->process($delivery);
        $delivery->refresh();
        $this->assertSame(['RETRY_SCHEDULED', 1, 'UNAVAILABLE', 'TRANSIENT'], [$delivery->status->value, $delivery->attempt_count, $delivery->last_error_code, $delivery->last_error_category->value]);
        $wait = now()->diffInSeconds($delivery->next_retry_at, false);
        $this->assertGreaterThanOrEqual(29, $wait);
        $this->assertLessThanOrEqual(61, $wait, '30 s + jitter');
        $this->assertNotNull($delivery->next_retry_at);

        $service->process($delivery);
        $delivery->refresh();
        $this->assertSame(['RETRY_SCHEDULED', 2], [$delivery->status->value, $delivery->attempt_count]);
        $this->assertGreaterThanOrEqual(59, now()->diffInSeconds($delivery->next_retry_at, false), 'the second wait doubles');

        $service->process($delivery);
        $delivery->refresh();
        $this->assertSame(['FAILED', 3], [$delivery->status->value, $delivery->attempt_count]);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame(3, $delivery->attempts()->count());
        $this->assertSame(['RETRY', 'RETRY', 'RETRY'], $delivery->attempts()->pluck('result')->all());
        $this->assertNull(CustomerDevice::query()->first()->revoked_at, 'a transient failure never deactivates the device');
    }

    public function test_the_lease_lets_only_one_worker_send_and_a_rate_limit_waits_at_least_a_minute(): void
    {
        [, $delivery] = $this->queuedPush('fake-token-quota-1');
        $service = app(NotificationDeliveryService::class);
        NotificationDelivery::query()->whereKey($delivery->getKey())->update(['status' => 'SENDING', 'sending_started_at' => now()]);
        $service->process($delivery); // a second worker while the first holds the row: nothing to lease, no attempt
        $this->assertSame(0, $delivery->attempts()->count());
        NotificationDelivery::query()->whereKey($delivery->getKey())->update(['status' => 'QUEUED', 'sending_started_at' => null]);
        $service->process($delivery->refresh());
        $delivery->refresh();
        $this->assertSame(1, $delivery->attempts()->count());
        $this->assertSame(['RETRY_SCHEDULED', 'QUOTA_EXCEEDED', 'RATE_LIMITED'], [$delivery->status->value, $delivery->last_error_code, $delivery->last_error_category->value]);
        $this->assertGreaterThanOrEqual(59, now()->diffInSeconds($delivery->next_retry_at, false));
        $this->assertGreaterThanOrEqual(60, $service->backoffSeconds(1, NotificationErrorCategory::RateLimited, null));
    }

    public function test_a_credential_failure_fails_the_delivery_pauses_the_push_provider_and_never_retries_in_a_storm(): void
    {
        [, $delivery] = $this->queuedPush('fake-token-authfail-1');
        $service = app(NotificationDeliveryService::class);
        $service->process($delivery);
        $delivery->refresh();
        $this->assertSame(['FAILED', 'UNAUTHENTICATED', 'AUTH_CONFIGURATION'], [$delivery->status->value, $delivery->last_error_code, $delivery->last_error_category->value]);
        $this->assertTrue(Cache::has(NotificationDeliveryService::AUTH_PAUSE_KEY));
        $this->assertNull(CustomerDevice::query()->first()->revoked_at);

        // The next push, while paused, is not even offered to the provider: it is scheduled again for after the pause.
        $this->orderStaff();
        $this->step($delivery->notification->subject_reference, 'accept')->assertOk();
        $accepted = CustomerNotification::query()->where('event_type', 'order.accepted')->firstOrFail();
        $next = NotificationDelivery::query()->where('notification_id', $accepted->getKey())->where('channel', 'PUSH')->firstOrFail();
        $service->process($next);
        $next->refresh();
        $this->assertSame(['RETRY_SCHEDULED', 'PROVIDER_PAUSED'], [$next->status->value, $next->last_error_code]);
        $this->assertSame('PROVIDER_PAUSED', $next->attempts()->first()->provider_code);
        $this->assertGreaterThanOrEqual(290, now()->diffInSeconds($next->next_retry_at, false));
    }

    public function test_a_stranded_sending_row_is_recovered_by_the_sweep_and_a_lost_queued_row_is_requeued(): void
    {
        [, $delivery] = $this->queuedPush('fake-token-ok-1');
        NotificationDelivery::query()->whereKey($delivery->getKey())->update(['status' => 'SENDING', 'sending_started_at' => now()->subMinutes(10), 'attempt_count' => 1]);
        $this->artisan('notifications:recover')->assertSuccessful();
        $delivery->refresh();
        $this->assertSame(['RETRY_SCHEDULED', 'STRANDED'], [$delivery->status->value, $delivery->last_error_code]);
        Bus::assertDispatchedTimes(SendNotificationDeliveryJob::class, 2);

        NotificationDelivery::query()->whereKey($delivery->getKey())->update(['status' => 'QUEUED', 'created_at' => now()->subMinutes(10)]);
        Bus::fake([SendNotificationDeliveryJob::class]);
        $this->artisan('notifications:recover')->assertSuccessful();
        Bus::assertDispatchedTimes(SendNotificationDeliveryJob::class, 1);

        // and when the job finally runs, the send succeeds and the attempt history shows the whole story
        app(NotificationDeliveryService::class)->process($delivery->refresh());
        $this->assertSame('SENT', $delivery->refresh()->status->value);
        $this->assertNotNull($delivery->provider_message_reference);
    }

    public function test_admin_sees_deliveries_and_attempts_without_tokens_retries_only_a_failed_delivery_with_a_live_device_and_everything_is_audited(): void
    {
        config(['notifications.retry.max_attempts' => 1]);
        [, $delivery] = $this->queuedPush('fake-token-transient-1');
        app(NotificationDeliveryService::class)->process($delivery);
        $this->assertSame('FAILED', $delivery->refresh()->status->value);
        $notification = $delivery->notification;

        $this->adminFor([Permission::AdminOrdersView]);
        $this->adminGet('/notifications')->assertForbidden();

        $this->adminFor([Permission::AdminNotificationsView]);
        $list = $this->adminGet('/notifications?status=FAILED&channel=PUSH')->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $this->assertSame($notification->public_id, $list['data'][0]['id']);
        $this->assertEquals(['PUSH' => ['FAILED' => 1], 'IN_APP' => ['SENT' => 1]], array_intersect_key($list['data'][0]['delivery_summary'], ['PUSH' => 1, 'IN_APP' => 1]));
        $this->assertStringNotContainsString('fake-token', json_encode($list));
        $this->assertStringNotContainsString('9876', json_encode($list), 'no phone number in the admin list');

        $detail = $this->adminGet('/notifications/'.$notification->public_id)->assertOk()->json('notification');
        $push = collect($detail['deliveries'])->firstWhere('channel', 'PUSH');
        $this->assertSame(['FAILED', true, 1], [$push['status'], $push['retryable'], count($push['attempts'])]);
        $this->assertSame(['RETRY', 'UNAVAILABLE', 'TRANSIENT'], [$push['attempts'][0]['result'], $push['attempts'][0]['provider_code'], $push['attempts'][0]['error_category']]);
        $this->assertSame('ACTIVE', $push['device']['token_state']);
        $this->assertStringNotContainsString('fake-token', json_encode($detail));
        $this->adminGet('/notifications/deliveries?status=FAILED')->assertOk()->assertJsonCount(1, 'data');
        $this->adminGet('/notifications/templates')->assertOk()->assertJsonPath('default_locale', 'en-IN')->assertJsonCount(14, 'types'); // 11 (Module 33) + review.response / review.published / review.moderated (Module 34)

        $this->adminPost('/notifications/deliveries/'.$push['id'].'/retry', ['reason' => 'provider recovered'])->assertForbidden();
        $this->adminFor([Permission::AdminNotificationsView, Permission::AdminNotificationsManage]);
        Bus::fake([SendNotificationDeliveryJob::class]);
        $this->adminPost('/notifications/deliveries/'.$push['id'].'/retry', ['reason' => 'provider recovered'])->assertOk()->assertJsonPath('delivery.status', 'RETRY_SCHEDULED');
        Bus::assertDispatched(SendNotificationDeliveryJob::class);
        $this->assertSame(1, AuditEvent::query()->where('action', 'notification.delivery_retried')->count());
        $this->adminPost('/notifications/deliveries/'.$push['id'].'/retry', ['reason' => 'again'])->assertStatus(409)->assertJsonPath('error.code', 'delivery_not_retryable');

        $inApp = collect($detail['deliveries'])->firstWhere('channel', 'IN_APP');
        $this->adminPost('/notifications/deliveries/'.$inApp['id'].'/retry', ['reason' => 'x'])->assertStatus(409);
    }

    public function test_the_system_report_tells_the_truth_about_the_providers_and_the_admin_test_send_is_gated_and_audited(): void
    {
        $this->adminFor([Permission::AdminSystemView]);
        $report = $this->adminGet('/system/notifications?fresh=1')->assertOk()->json();
        $this->assertSame('fake', $report['push']['driver']);
        $this->assertStringContainsString('PENDING FIREBASE CONFIGURATION', $report['push']['detail']);
        $this->assertSame('OPERATIONAL', $report['status']);
        $this->assertStringContainsString('REAL EMAIL PROVIDER = PENDING', $report['notes']['email']);
        $this->assertArrayHasKey('push_sent', $report['counters']);
        $this->assertSame(0, $report['queue']['stranded']);

        config(['notifications.channels.PUSH.driver' => 'fcm']);
        $fcm = $this->adminGet('/system/notifications?fresh=1')->assertOk()->json();
        $this->assertSame(['UNAVAILABLE', 'fcm'], [$fcm['push']['status'], $fcm['push']['driver']]);
        $this->assertStringContainsString('PENDING FIREBASE CONFIGURATION', $fcm['push']['detail']);
        config(['notifications.channels.PUSH.driver' => 'fake']);

        [$customer, $headers] = $this->customerReadyForCheckout();
        $this->registerDevice($headers)->assertCreated();
        $this->adminFor([Permission::AdminNotificationsView]);
        $this->adminPost('/notifications/test', ['customer_id' => $customer->public_id, 'type' => 'order.ready'])->assertForbidden();
        $this->adminFor([Permission::AdminNotificationsTest]);
        $this->adminPost('/notifications/test', ['customer_id' => $customer->public_id, 'type' => 'order.ready', 'title' => 'free text'])->assertStatus(422);
        $this->adminPost('/notifications/test', ['customer_id' => $customer->public_id, 'type' => 'nope'])->assertStatus(422)->assertJsonPath('error.code', 'notification_type_unknown');
        $sent = $this->adminPost('/notifications/test', ['customer_id' => $customer->public_id, 'type' => 'order.ready'])->assertCreated()->json('notification');
        $this->assertSame('order.ready', $sent['type']);
        $this->assertSame('SENT', collect($sent['deliveries'])->firstWhere('channel', 'PUSH')['status']);
        $this->assertSame(1, AuditEvent::query()->where('action', 'notification.test_sent')->count());
        config(['notifications.admin_test_enabled' => false]);
        $this->adminPost('/notifications/test', ['customer_id' => $customer->public_id, 'type' => 'order.ready'])->assertNotFound();
    }
}
