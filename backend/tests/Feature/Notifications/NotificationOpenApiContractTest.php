<?php

namespace Tests\Feature\Notifications;

use App\Enums\Permission;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\BuildsNotifications;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * The notification API is what its document says (Module 33): every new route is documented and every answer matches
 * its schema — the customer's devices and inbox, the admin's diagnostics, registry, resend, system report and test send.
 */
class NotificationOpenApiContractTest extends TestCase
{
    use BuildsNotifications, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_every_answer_matches_its_schema(): void
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices(['inst-phone-00000001' => 'fake-token-phone-1']);
        $this->assertMatchesSchema('CustomerDeviceEnvelope', $this->registerDevice($headers, 'inst-phone-00000001', 'fake-token-phone-1b')->assertOk()->json());
        $device = $this->withHeaders($this->using($headers))->withHeader('X-Installation-Id', 'inst-phone-00000001')->getJson('/api/v1/customer/devices')->assertOk();
        $this->assertMatchesSchema('CustomerDeviceList', $device->json());
        $this->assertMatchesSchema('CustomerDeviceEnvelope', $this->withHeaders($this->using($headers))->patchJson('/api/v1/customer/devices/'.$device->json('data.0.id'), ['app_version' => '0.1.0+37'])->assertOk()->json());
        $this->assertMatchesSchema('NotificationPreferences', $this->withHeaders($this->using($headers))->getJson('/api/v1/customer/notification-preferences')->assertOk()->json());

        $inbox = $this->inbox($headers)->assertOk();
        $this->assertMatchesSchema('CustomerNotificationPage', $inbox->json());
        $this->assertMatchesSchema('UnreadCount', $this->withHeaders($this->using($headers))->getJson('/api/v1/customer/notifications/unread-count')->assertOk()->json());
        $this->assertMatchesSchema('NotificationReadEnvelope', $this->withHeaders($this->using($headers))->postJson('/api/v1/customer/notifications/'.$inbox->json('data.0.id').'/read')->assertOk()->json());
        $this->assertMatchesSchema('NotificationReadAll', $this->withHeaders($this->using($headers))->postJson('/api/v1/customer/notifications/read-all')->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->withHeaders($this->using($headers))->postJson('/api/v1/customer/notifications/00000000-0000-0000-0000-000000000001/read')->assertNotFound()->json());

        // a failed push to resend
        config(['notifications.retry.max_attempts' => 1]);
        Bus::fake();
        $this->registerDevice($headers, 'inst-flaky-00000001', 'fake-token-transient-9')->assertCreated();
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $accepted = CustomerNotification::query()->where('event_type', 'order.accepted')->firstOrFail();
        $failed = NotificationDelivery::query()->where('notification_id', $accepted->getKey())->where('channel', 'PUSH')->whereHas('device', fn ($d) => $d->where('installation_id', 'inst-flaky-00000001'))->firstOrFail();
        app(NotificationDeliveryService::class)->process($failed);
        $this->assertSame('FAILED', $failed->refresh()->status->value);

        $this->adminFor([Permission::AdminNotificationsView, Permission::AdminNotificationsManage, Permission::AdminNotificationsTest, Permission::AdminSystemView]);
        $this->assertMatchesSchema('AdminNotificationPage', $this->adminGet('/notifications?q='.$order['order_number'])->assertOk()->json());
        $this->assertMatchesSchema('AdminNotificationEnvelope', $this->adminGet('/notifications/'.$accepted->public_id)->assertOk()->json());
        $this->assertMatchesSchema('AdminDeliveryPage', $this->adminGet('/notifications/deliveries')->assertOk()->json());
        $this->assertMatchesSchema('NotificationRegistry', $this->adminGet('/notifications/templates')->assertOk()->json());
        $this->assertMatchesSchema('AdminDeliveryEnvelope', $this->adminPost('/notifications/deliveries/'.$failed->public_id.'/retry', ['reason' => 'contract'])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->adminPost('/notifications/deliveries/'.$failed->public_id.'/retry', ['reason' => 'contract'])->assertStatus(409)->json());
        $this->assertMatchesSchema('NotificationSystem', $this->adminGet('/system/notifications?fresh=1')->assertOk()->json());
        $this->assertMatchesSchema('AdminNotificationEnvelope', $this->adminPost('/notifications/test', ['customer_id' => $customer->public_id, 'type' => 'order.ready'])->assertCreated()->json());

        $this->withHeaders($this->using($headers))->deleteJson('/api/v1/customer/devices/'.$device->json('data.0.id'))->assertNoContent();
        $this->withHeaders($this->using($headers))->deleteJson('/api/v1/customer/devices/current', ['installation_id' => 'inst-flaky-00000001'])->assertNoContent();
    }

    public function test_every_notification_route_is_documented_and_nothing_is_documented_that_does_not_exist(): void
    {
        $document = $this->openApi();
        $mine = fn (string $uri): bool => str_starts_with($uri, 'customer/devices') || str_starts_with($uri, 'customer/notifications') || str_starts_with($uri, 'admin/notifications') || $uri === 'admin/system/notifications';
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => [preg_replace('#^api/v1/#', '', $r->uri()), array_diff($r->methods(), ['HEAD'])])->filter(fn (array $r): bool => $mine($r[0]))
            ->flatMap(fn (array $r): array => array_map(fn (string $m): string => strtolower($m).' /'.$r[0], $r[1]))->sort()->values()->all();
        $documented = collect($document['paths'])->filter(fn ($ops, string $path): bool => $mine(ltrim($path, '/')))
            ->flatMap(fn (array $ops, string $path): array => array_map(fn (string $m): string => $m.' '.$path, array_keys($ops)))->sort()->values()->all();
        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(16, $routes);   // 9 customer (devices ×5, inbox ×4) + 7 admin (list, detail, deliveries, templates, retry, test, system)
    }
}
