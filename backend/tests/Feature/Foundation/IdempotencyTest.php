<?php

namespace Tests\Feature\Foundation;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private static int $executions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$executions = 0;

        Route::middleware(['api', 'auth:sanctum', 'idempotent:test.charge'])->post('/api/v1/_test/charge', function (Request $request) {
            self::$executions++;

            return response()->json(['execution' => self::$executions, 'amount' => $request->integer('amount')], 201);
        });
    }

    public function test_a_missing_or_malformed_key_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/_test/charge', ['amount' => 24900])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->postJson('/api/v1/_test/charge', ['amount' => 24900], ['Idempotency-Key' => 'short'])->assertStatus(400);
        $this->assertSame(0, self::$executions);
    }

    public function test_a_retry_with_the_same_key_and_request_replays_the_first_outcome_without_running_again(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $headers = ['Idempotency-Key' => 'checkout-0001-aaaa-bbbb'];

        $first = $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated();
        $retry = $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertEquals($first->json(), $retry->json());
        $this->assertSame(1, self::$executions);
        $this->assertNull($first->headers->get('Idempotency-Replayed'));
    }

    public function test_reusing_a_key_for_a_different_request_is_a_conflict(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $headers = ['Idempotency-Key' => 'checkout-0002-aaaa-bbbb'];

        $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated();
        $this->postJson('/api/v1/_test/charge', ['amount' => 99900], $headers)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(1, self::$executions);
    }

    public function test_keys_are_scoped_to_the_actor(): void
    {
        $headers = ['Idempotency-Key' => 'checkout-0003-aaaa-bbbb'];

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated()->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(2, self::$executions);
    }

    public function test_a_request_still_in_progress_is_not_executed_twice(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $body = ['amount' => 24900];

        IdempotencyKey::query()->create([
            'actor' => 'user:'.$user->id, 'operation' => 'test.charge', 'key' => 'checkout-0004-aaaa-bbbb',
            'request_hash' => hash('sha256', 'POST|api/v1/_test/charge|'.json_encode($body)), 'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/v1/_test/charge', $body, ['Idempotency-Key' => 'checkout-0004-aaaa-bbbb'])
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_in_progress');
        $this->assertSame(0, self::$executions);
    }

    public function test_keys_expire_and_expired_keys_are_pruned(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $headers = ['Idempotency-Key' => 'checkout-0005-aaaa-bbbb'];

        $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated();
        $this->travel((int) config('api.idempotency.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/v1/_test/charge', ['amount' => 24900], $headers)->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame(2, self::$executions);

        $this->travel((int) config('api.idempotency.ttl_minutes') + 1)->minutes();
        Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]);
        $this->assertSame(0, IdempotencyKey::query()->count());
    }
}
