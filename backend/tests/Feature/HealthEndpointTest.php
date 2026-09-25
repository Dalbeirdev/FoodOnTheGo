<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_status_without_exposing_secrets(): void
    {
        Redis::shouldReceive('connection->ping')->once()->andReturn(true);

        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'app' => config('app.name'),
                'environment' => 'testing',
                'database' => 'ok',
                'redis' => 'ok',
            ])
            ->assertJsonStructure(['status', 'app', 'environment', 'database', 'redis', 'version', 'time']);

        $body = $response->getContent();

        $this->assertStringNotContainsString(config('database.connections.mysql.password') ?: 'CHANGE_ME_LOCAL', $body);
        $this->assertStringNotContainsString('127.0.0.1', $body);
    }

    public function test_health_endpoint_is_degraded_when_redis_is_unreachable(): void
    {
        Redis::shouldReceive('connection->ping')->once()->andThrow(new \RuntimeException('connection refused'));

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJson(['status' => 'degraded', 'redis' => 'error', 'database' => 'ok']);
    }
}
