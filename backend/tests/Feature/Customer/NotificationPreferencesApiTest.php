<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Notification preferences (Module 25): the category × channel matrix, safe defaults, marketing never inferred,
 * locked security channels, unknown categories / channels refused, consent timestamps, ownership.
 */
class NotificationPreferencesApiTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase;

    private Customer $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->rahul = $this->customer($this->market);
        $this->actingAsPrincipal($this->rahul);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function enabled(array $matrix): array
    {
        $out = [];
        foreach ($matrix['categories'] as $category) {
            foreach ($category['channels'] as $channel) {
                $out[$category['category']][$channel['channel']] = $channel['enabled'];
            }
        }

        return $out;
    }

    public function test_defaults_keep_transactional_notices_on_and_marketing_off(): void
    {
        $m = $this->getJson('/api/v1/customer/notification-preferences')->assertOk()->json();
        $this->assertSame(['ORDER_UPDATES', 'PICKUP_UPDATES', 'PAYMENT_UPDATES', 'ACCOUNT_SECURITY', 'PROMOTIONS', 'PRODUCT_UPDATES'], array_column($m['categories'], 'category'));
        $this->assertSame(['PUSH', 'SMS', 'EMAIL', 'IN_APP'], $m['channels']);
        $on = $this->enabled($m);
        $this->assertSame(['PUSH' => true, 'SMS' => true, 'EMAIL' => true, 'IN_APP' => true], $on['ORDER_UPDATES']);
        $this->assertSame(['PUSH' => false, 'SMS' => false, 'EMAIL' => false, 'IN_APP' => false], $on['PROMOTIONS'], 'consent is never assumed');
        $this->assertSame(['PUSH' => true, 'SMS' => true, 'EMAIL' => true, 'IN_APP' => true], $on['ACCOUNT_SECURITY']);
        $security = collect($m['categories'])->firstWhere('category', 'ACCOUNT_SECURITY');
        $this->assertSame(['PUSH' => false, 'SMS' => true, 'EMAIL' => false, 'IN_APP' => true], collect($security['channels'])->mapWithKeys(fn ($c) => [$c['channel'] => $c['locked']])->all());
        $this->assertTrue($security['transactional']);
        $this->assertFalse(collect($m['categories'])->firstWhere('category', 'PROMOTIONS')['transactional']);
        $this->assertSame(['granted_at' => null, 'withdrawn_at' => null], $m['marketing_consent']);
        $this->assertSame(0, DB::table('customer_notification_preferences')->count(), 'defaults are not stored');
    }

    public function test_choices_are_stored_per_cell_marketing_consent_is_explicit_and_audited(): void
    {
        $m = $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [
            ['category' => 'ORDER_UPDATES', 'channel' => 'EMAIL', 'enabled' => false],
            ['category' => 'PROMOTIONS', 'channel' => 'EMAIL', 'enabled' => true],
            ['category' => 'PRODUCT_UPDATES', 'channel' => 'PUSH', 'enabled' => true],
        ]])->assertOk()->json();
        $on = $this->enabled($m);
        $this->assertFalse($on['ORDER_UPDATES']['EMAIL']);
        $this->assertTrue($on['ORDER_UPDATES']['PUSH'], 'other channels keep their defaults');
        $this->assertTrue($on['PROMOTIONS']['EMAIL']);
        $this->assertFalse($on['PROMOTIONS']['PUSH'], 'consent for one channel is not consent for another');
        $this->assertNotNull($m['marketing_consent']['granted_at']);
        $this->assertNull($m['marketing_consent']['withdrawn_at']);
        $this->assertSame(3, DB::table('customer_notification_preferences')->count());
        $audit = DB::table('audit_events')->where('action', 'customer.notification_preferences_changed')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(['from' => false, 'to' => true], json_decode($audit->changes, true)['PROMOTIONS.EMAIL']);

        $m = $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'PROMOTIONS', 'channel' => 'EMAIL', 'enabled' => false]]])->assertOk()->json();
        $this->assertNotNull($m['marketing_consent']['withdrawn_at']);
        $this->assertFalse($this->enabled($m)['PROMOTIONS']['EMAIL']);
        // the same choice again is not a change
        $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'PROMOTIONS', 'channel' => 'EMAIL', 'enabled' => false]]])->assertOk();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'customer.notification_preferences_changed')->count());
    }

    public function test_security_notices_cannot_be_switched_off_on_their_locked_channels_and_unknown_cells_are_refused(): void
    {
        $locked = $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'ACCOUNT_SECURITY', 'channel' => 'SMS', 'enabled' => false]]])
            ->assertUnprocessable()->json('error.details.fields');
        $this->assertSame(['Security notices on this channel cannot be switched off.'], $locked['preferences.0.enabled']);
        $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'ACCOUNT_SECURITY', 'channel' => 'EMAIL', 'enabled' => false]]])->assertOk();
        $this->assertFalse($this->enabled($this->getJson('/api/v1/customer/notification-preferences')->json())['ACCOUNT_SECURITY']['EMAIL']);
        $this->assertTrue($this->enabled($this->getJson('/api/v1/customer/notification-preferences')->json())['ACCOUNT_SECURITY']['SMS']);
        $fields = fn (array $prefs): array => $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => $prefs])->assertUnprocessable()->json('error.details.fields');
        $this->assertArrayHasKey('preferences.0.category', $fields([['category' => 'GOSSIP', 'channel' => 'PUSH', 'enabled' => true]]));
        $this->assertArrayHasKey('preferences.0.channel', $fields([['category' => 'PROMOTIONS', 'channel' => 'PIGEON', 'enabled' => true]]));
        $this->assertArrayHasKey('preferences.0.enabled', $fields([['category' => 'PROMOTIONS', 'channel' => 'PUSH', 'enabled' => 'yes please']]));
        $this->assertArrayHasKey('preferences', $fields([]));
        $this->assertSame(1, DB::table('customer_notification_preferences')->count(), 'a refused request stores nothing new');
    }

    public function test_preferences_belong_to_their_owner(): void
    {
        $this->patchJson('/api/v1/customer/notification-preferences', ['preferences' => [['category' => 'PROMOTIONS', 'channel' => 'PUSH', 'enabled' => true]]])->assertOk();
        $this->actingAsPrincipal($this->customer($this->market));
        $this->assertFalse($this->enabled($this->getJson('/api/v1/customer/notification-preferences')->json())['PROMOTIONS']['PUSH']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/customer/notification-preferences')->assertUnauthorized();
    }
}
