<?php

namespace Tests\Feature\Notifications;

use App\Models\CustomerDevice;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsNotifications;
use Tests\TestCase;

class CustomerDeviceTest extends TestCase
{
    use BuildsNotifications, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_registering_the_current_installation_is_idempotent_binds_it_to_the_signed_in_customer_and_never_returns_the_token(): void
    {
        [$customer, $headers] = $this->customerReadyForCheckout();
        $first = $this->registerDevice($headers)->assertCreated()->json('device');
        $this->assertSame(['ANDROID', 'FAKE', true, 'GRANTED', true], [$first['platform'], $first['push_provider'], $first['active'], $first['notifications_permission_state'], $first['current']]);
        $this->assertArrayNotHasKey('push_token', $first);
        $this->assertStringNotContainsString('fake-token-rahul-phone-1', $this->registerDevice($headers)->assertOk()->getContent());

        $again = $this->registerDevice($headers, 'inst-rahul-phone-1', 'fake-token-rahul-phone-1-refreshed')->assertOk()->json('device');
        $this->assertSame($first['id'], $again['id'], 'the same installation stays one row');
        $this->assertSame(1, CustomerDevice::query()->count());
        $row = CustomerDevice::query()->first();
        $this->assertSame((int) $customer->getKey(), (int) $row->customer_id);
        $this->assertSame(hash('sha256', 'fake-token-rahul-phone-1-refreshed'), $row->push_token_hash);
        $this->assertNotSame('fake-token-rahul-phone-1-refreshed', DB::table('customer_devices')->value('push_token'), 'the token is encrypted at rest');
        $this->assertSame('fake-token-rahul-phone-1-refreshed', $row->push_token, 'and readable by the server only');

        $list = $this->withHeaders($this->using($headers))->withHeader('X-Installation-Id', 'inst-rahul-phone-1')->getJson('/api/v1/customer/devices')->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $this->assertTrue($list['data'][0]['current']);
        $this->assertStringNotContainsString('refreshed', json_encode($list));
    }

    public function test_input_is_bounded_and_a_customer_id_is_never_accepted(): void
    {
        [, $headers] = $this->customerReadyForCheckout();
        $this->registerDevice($headers, 'short', 'fake-token-x')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => ['installation_id']]]]);
        $this->registerDevice($headers, 'inst-ok-00000001', 'fake-token-x', ['platform' => 'IOS'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => ['platform']]]]);
        $this->registerDevice($headers, 'inst-ok-00000001', 'fake-token-x', ['customer_id' => '00000000-0000-0000-0000-000000000001'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => ['customer_id']]]]);
        $this->registerDevice($headers, 'inst-ok-00000001', 'bad token with spaces')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['fields' => ['push_token']]]]);
        $this->withHeaders($this->using($headers))->putJson('/api/v1/customer/devices/current', ['installation_id' => 'inst-ok-00000001', 'platform' => 'ANDROID', 'push_provider' => 'FAKE', 'push_token' => str_repeat('a', 4097)])->assertStatus(422);
        $this->flushHeaders(); // withHeaders() persists for the test: a guest request must drop the bearer and the guard
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/customer/devices/current', ['installation_id' => 'inst-ok-00000001', 'platform' => 'ANDROID', 'push_provider' => 'FAKE', 'push_token' => 'fake-token-x'])->assertStatus(401);
    }

    public function test_another_customer_signing_in_on_the_same_installation_ends_the_previous_binding_and_a_token_seen_elsewhere_is_reassigned(): void
    {
        [$rahul, $rahulHeaders] = $this->customerReadyForCheckout();
        [$asha, $ashaHeaders] = $this->otherCustomer();
        $this->registerDevice($rahulHeaders, 'inst-shared-phone-1', 'fake-token-shared-1')->assertCreated();

        // Rahul signs out of the shared phone; Asha signs in on it.
        $this->registerDevice($ashaHeaders, 'inst-shared-phone-1', 'fake-token-shared-1')->assertCreated();
        $rows = CustomerDevice::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([(int) $rahul->getKey(), 'REBOUND'], [(int) $rows[0]->customer_id, $rows[0]->revoke_reason->value]);
        $this->assertSame([(int) $asha->getKey(), null], [(int) $rows[1]->customer_id, $rows[1]->revoked_at]);
        $this->assertSame(1, SecurityEvent::query()->where('event', 'DEVICE_REBOUND')->count());

        // The same token later turns up on another installation of Rahul's: the older holder (Asha's row) ends.
        $this->registerDevice($rahulHeaders, 'inst-rahul-phone-2', 'fake-token-shared-1')->assertCreated();
        $this->assertSame('TOKEN_REASSIGNED', CustomerDevice::query()->whereKey($rows[1]->getKey())->first()->revoke_reason->value);
        $this->assertSame(1, CustomerDevice::query()->whereNull('revoked_at')->count());
        $this->assertSame(1, CustomerDevice::query()->where('push_token_hash', hash('sha256', 'fake-token-shared-1'))->whereNull('revoked_at')->count(), 'one active holder per token');
    }

    public function test_a_customer_cannot_read_update_or_revoke_another_customers_device(): void
    {
        [, $rahulHeaders] = $this->customerReadyForCheckout();
        [, $ashaHeaders] = $this->otherCustomer();
        $device = $this->registerDevice($rahulHeaders, 'inst-rahul-phone-1', 'fake-token-rahul-1')->assertCreated()->json('device');

        $this->withHeaders($this->using($ashaHeaders))->patchJson('/api/v1/customer/devices/'.$device['id'], ['notifications_permission_state' => 'DENIED'])->assertNotFound()->assertJsonPath('error.code', 'device_not_found');
        $this->withHeaders($this->using($ashaHeaders))->deleteJson('/api/v1/customer/devices/'.$device['id'])->assertNotFound();
        $this->assertSame(0, (int) $this->withHeaders($this->using($ashaHeaders))->getJson('/api/v1/customer/devices')->assertOk()->json('data.0.active', 0));
        $this->assertNull(CustomerDevice::query()->first()->revoked_at);

        $this->withHeaders($this->using($rahulHeaders))->patchJson('/api/v1/customer/devices/'.$device['id'], ['notifications_permission_state' => 'DENIED', 'app_version' => '0.1.0+38'])->assertOk()->assertJsonPath('device.notifications_permission_state', 'DENIED');
        $this->withHeaders($this->using($rahulHeaders))->deleteJson('/api/v1/customer/devices/'.$device['id'])->assertNoContent();
        $this->assertSame('CUSTOMER', CustomerDevice::query()->first()->revoke_reason->value);
    }

    public function test_logout_with_the_installation_id_ends_the_binding_and_logout_everywhere_ends_every_binding(): void
    {
        [$customer, $headers] = $this->customerReadyForCheckout();
        $this->registerDevice($headers, 'inst-rahul-phone-1', 'fake-token-1')->assertCreated();
        $this->registerDevice($headers, 'inst-rahul-tablet-1', 'fake-token-2')->assertCreated();

        $this->withHeaders($this->using($headers))->postJson('/api/v1/auth/logout', ['installation_id' => 'inst-rahul-phone-1'])->assertNoContent();
        $this->assertSame(['LOGOUT', null], [CustomerDevice::query()->where('installation_id', 'inst-rahul-phone-1')->first()->revoke_reason->value, CustomerDevice::query()->where('installation_id', 'inst-rahul-tablet-1')->first()->revoked_at]);

        $session = $this->sessionOf($customer, 'tablet');
        $this->withHeaders($this->using($session))->deleteJson('/api/v1/customer/devices/current', ['installation_id' => 'inst-rahul-tablet-1'])->assertNoContent();
        $this->assertSame(0, CustomerDevice::query()->whereNull('revoked_at')->count());

        $this->registerDevice($session, 'inst-rahul-phone-3', 'fake-token-3')->assertCreated();
        $this->withHeaders($this->using($session))->postJson('/api/v1/auth/logout-all')->assertNoContent();
        $this->assertSame(0, CustomerDevice::query()->whereNull('revoked_at')->count());
    }

    public function test_the_number_of_active_devices_per_customer_is_bounded_and_the_oldest_gives_way(): void
    {
        config(['notifications.limits.devices_per_customer' => 2]);
        [, $headers] = $this->customerReadyForCheckout();
        $this->registerDevice($headers, 'inst-a-00000001', 'fake-token-a')->assertCreated();
        $this->travel(1)->minutes();
        $this->registerDevice($headers, 'inst-b-00000001', 'fake-token-b')->assertCreated();
        $this->travel(1)->minutes();
        $this->registerDevice($headers, 'inst-c-00000001', 'fake-token-c')->assertCreated();
        $this->assertSame(2, CustomerDevice::query()->whereNull('revoked_at')->count());
        $this->assertSame('REPLACED', CustomerDevice::query()->where('installation_id', 'inst-a-00000001')->first()->revoke_reason->value);
    }
}
