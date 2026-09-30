<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
}
