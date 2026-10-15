<?php

namespace Tests\Feature\Order;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOrders;
use Tests\TestCase;

/**
 * Module 40 — the pickup verification locks its order and credential FOR NO KEY UPDATE: exclusive between verifiers,
 * but never in conflict with the foreign-key checks of a refused attempt's row. With FOR UPDATE, the load test's
 * pickup race (eight simultaneous verifications of one code) deadlocked and answered 500 instead of 409. The race
 * itself is measured by tests/performance/run.mjs --profile race (a single test connection cannot race).
 */
class PickupLockingTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_verification_locks_its_rows_without_blocking_foreign_key_checks_and_still_verifies_once(): void
    {
        [, $customer, $order] = $this->readyOrder();
        $payload = $this->credentialOf($customer, $order['order_number'])['qr_payload'];
        $locks = [];
        DB::listen(function (QueryExecuted $q) use (&$locks): void {
            if (preg_match('/ for (no key update|update|share|key share)$/', $q->sql, $m) && preg_match('/from "(orders|order_pickup_credentials)"/', $q->sql, $t)) {
                $locks[] = $t[1].': '.$m[1];
            }
        });

        $this->verifyQr($payload)->assertOk()->assertJsonPath('result', 'PICKUP_VERIFIED');
        $this->assertContains('orders: no key update', $locks);
        $this->assertContains('order_pickup_credentials: no key update', $locks);
        $this->assertNotContains('order_pickup_credentials: update', $locks);

        // A second verification of the same code: refused as already verified (its attempt row is written), never twice.
        $this->verifyQr($payload)->assertStatus(409)->assertJsonPath('error.code', 'pickup_already_verified');
        $this->assertSame(1, DB::table('order_pickup_verification_attempts')->where('result', 'SUCCESS')->count());
    }
}
