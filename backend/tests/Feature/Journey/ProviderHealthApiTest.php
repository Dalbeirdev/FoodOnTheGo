<?php

namespace Tests\Feature\Journey;

use App\Enums\Permission;
use App\Enums\ProviderFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * GET /admin/system/providers — provider configuration and health for platform administrators, derived from
 * the platform's own calls (UNKNOWN until something was called).
 */
class ProviderHealthApiTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
    }

    public function test_only_administrators_with_the_system_permission_see_provider_health(): void
    {
        $this->getJson('/api/v1/admin/system/providers')->assertUnauthorized();

        $this->actingAsPrincipal($this->adminWith([Permission::AdminMarketsView]));
        $this->getJson('/api/v1/admin/system/providers')->assertForbidden();

        $this->actingAsPrincipal($this->customer($this->market));
        $this->getJson('/api/v1/admin/system/providers')->assertUnauthorized();
    }

    public function test_health_is_unknown_until_the_platform_has_called_a_provider_and_follows_real_calls(): void
    {
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));

        $before = $this->getJson('/api/v1/admin/system/providers')->assertOk();
        $this->assertTrue($before->json('fake_allowed'));
        $this->assertSame(['places', 'routing'], $before->json('providers.*.capability'));
        $this->assertSame(['development', 'development'], $before->json('providers.*.mode'));
        $this->assertSame(['fake', 'fake'], $before->json('providers.*.driver'));
        $this->assertSame(['UNKNOWN', 'UNKNOWN'], $before->json('providers.*.health.state'));
        $this->assertSame([true, true], $before->json('providers.*.configured'));
        $this->assertSame(0, $before->json('journeys.active'));
        $this->assertSame(30, $before->json('journeys.ttl_minutes'));

        $this->planGuest();
        $this->getJson('/api/v1/places/search?q=delhi')->assertOk();

        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $after = $this->getJson('/api/v1/admin/system/providers')->assertOk();
        // One route was planned and one place search made: each capability counts its own calls.
        $this->assertSame(['OPERATIONAL', 'OPERATIONAL'], $after->json('providers.*.health.state'));
        $this->assertSame([1, 1], $after->json('providers.*.health.calls'));
        $this->assertSame(0, $after->json('providers.1.health.failures'));
        $this->assertNotNull($after->json('providers.1.health.avg_latency_ms'));
        $this->assertNotNull($after->json('providers.1.health.last_call_at'));
        $this->assertSame(1, $after->json('journeys.active'));

        // A failing route service is not hidden behind a healthy place search of the same driver.
        $this->fakeRouting()->failNext('route', ProviderFailure::Unavailable, 2);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/journeys/plan', $this->planBody(['destination' => ['lat' => 28.57, 'lng' => 77.4]]))->assertStatus(503);
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView]));
        $failing = $this->getJson('/api/v1/admin/system/providers')->assertOk();
        $this->assertSame(['OPERATIONAL', 'OUTAGE'], $failing->json('providers.*.health.state'));
        $this->assertSame([0, 2], $failing->json('providers.*.health.failures'));
        $this->assertArrayNotHasKey('key', $after->json('providers.0'));
        $this->assertStringNotContainsString('API_KEY=', json_encode($after->json()));
    }
}
