<?php

namespace Tests;

use App\Auth\Principal;
use App\Models\AccessToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests reset their database. Refuse to start against anything that is not a dedicated test database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (config('app.env') !== 'testing' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against [{$database}] in environment [".config('app.env').'].');
        }
    }

    /**
     * Signs the principal in on its own guard only (customer / restaurant / admin), like a real token would.
     *
     * @param  list<string>  $abilities
     */
    protected function actingAsPrincipal(Principal $principal, array $abilities = [AccessToken::ACCESS]): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($principal, $abilities, $principal->principalType()->guard());

        return $this;
    }

    /**
     * A real bearer token for the principal, for tests that must exercise token lookup, expiry or revocation.
     *
     * @param  list<string>  $abilities
     * @return array{Authorization: string}
     */
    protected function bearer(Principal $principal, string $device = 'test', array $abilities = [AccessToken::ACCESS]): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$principal->createToken($device, $abilities, now()->addHour())->plainTextToken];
    }
}
