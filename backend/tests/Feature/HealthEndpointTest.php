<?php

namespace Tests\Feature;

use App\Auth\AccessControl;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_is_minimal_and_exposes_no_infrastructure_detail(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(['status', 'service', 'version', 'time'], array_keys($response->json()));

        $body = $response->getContent();
        foreach (['127.0.0.1', 'pgsql', 'redis', 'postgis', 'testing', (string) config('database.connections.pgsql.username')] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
    }

    public function test_public_health_is_degraded_with_503_when_a_dependency_is_down_without_saying_which(): void
    {
        Redis::shouldReceive('connection->ping')->andThrow(new \RuntimeException('connection refused 127.0.0.1:6379'));

        $response = $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('status', 'degraded');

        $this->assertStringNotContainsString('refused', $response->getContent());
        $this->assertStringNotContainsString('redis', $response->getContent());
    }

    public function test_readiness_reports_each_real_dependency_where_it_is_public(): void
    {
        $this->getJson('/api/v1/ready')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'environment', 'checks' => ['application', 'database', 'postgis', 'redis'], 'version', 'time'])
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.postgis', 'ok')
            ->assertJsonPath('checks.redis', 'ok');
    }

    public function test_readiness_is_restricted_to_permitted_admins_when_it_is_not_public(): void
    {
        config(['api.readiness.public' => false]);

        $this->getJson('/api/v1/ready')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/ready')->assertForbidden();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/ready')->assertForbidden();

        app(AccessControl::class)->grant($admin, Permission::AdminSystemView);
        $this->getJson('/api/v1/ready')->assertOk()->assertJsonPath('checks.postgis', 'ok');
    }

    public function test_the_unversioned_health_route_no_longer_exists(): void
    {
        $this->getJson('/api/health')->assertNotFound();
    }
}
