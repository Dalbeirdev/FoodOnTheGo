<?php

namespace Tests\Feature\Foundation;

use App\Exceptions\ApiException;
use App\Models\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiConventionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function (): void {
            Route::get('/boom', fn () => DB::select('select * from table_that_does_not_exist'));
            Route::get('/conflict', fn () => throw ApiException::conflict('pickup_slot_full', 'This pickup time is no longer available.', ['slot' => '18:30']));
            Route::get('/limited', fn () => ['ok' => true])->middleware('throttle:2,1');
        });
    }

    public function test_every_response_carries_a_request_id_and_a_client_supplied_one_is_propagated(): void
    {
        $generated = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generated);

        $this->getJson('/api/v1/health', ['X-Request-Id' => 'web-7f3a9c21-checkout'])
            ->assertHeader('X-Request-Id', 'web-7f3a9c21-checkout');

        $rejected = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\twith spaces"])->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $rejected);
    }

    public function test_unknown_route_returns_the_standard_404_error(): void
    {
        $response = $this->getJson('/api/v1/nope', ['X-Request-Id' => 'trace-12345678'])->assertNotFound();

        $response->assertExactJson(['error' => [
            'code' => 'not_found',
            'message' => 'The requested resource was not found.',
            'request_id' => 'trace-12345678',
        ]]);
    }

    public function test_wrong_method_returns_405(): void
    {
        $this->postJson('/api/v1/health')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_domain_errors_use_their_own_status_code_and_details(): void
    {
        $this->getJson('/api/v1/_test/conflict')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'pickup_slot_full')
            ->assertJsonPath('error.message', 'This pickup time is no longer available.')
            ->assertJsonPath('error.details.slot', '18:30');
    }

    public function test_server_errors_never_leak_sql_paths_or_traces_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500)->assertJsonPath('error.code', 'internal_error');

        $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
        foreach (['table_that_does_not_exist', 'SQLSTATE', 'select', 'vendor', '.php', 'trace', 'QueryException'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_debug_detail_appears_only_under_a_debug_key_when_debug_is_on(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500);

        $this->assertSame('Something went wrong on our side.', $response->json('error.message'));
        $this->assertStringContainsString('QueryException', $response->json('debug.exception'));
    }

    public function test_rate_limited_requests_return_429_with_retry_information(): void
    {
        $this->getJson('/api/v1/_test/limited')->assertOk();
        $this->getJson('/api/v1/_test/limited')->assertOk();

        $response = $this->getJson('/api/v1/_test/limited')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertIsInt($response->json('error.details.retry_after_seconds'));
    }

    public function test_the_versioned_api_group_is_rate_limited_by_the_configurable_api_limiter(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertSame((string) config('rate_limits.api'), $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_api_responses_carry_baseline_security_headers(): void
    {
        Market::factory()->india()->create();

        $this->getJson('/api/v1/markets/current')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->getJson('/api/v1/nope')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cors_allows_configured_origins_only(): void
    {
        $allowed = config('cors.allowed_origins')[0];

        $this->getJson('/api/v1/health', ['Origin' => $allowed])->assertHeader('Access-Control-Allow-Origin', $allowed);
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNull($this->getJson('/api/v1/health', ['Origin' => 'https://evil.example'])->headers->get('Access-Control-Allow-Origin'));
    }
}
