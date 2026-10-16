<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Module 41: the API host's root is a minimal service note — no framework welcome page, no front-end assets.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertExactJson(['service' => 'FoodOnTheGo API', 'api' => '/api/v1']);
        $this->assertStringNotContainsStringIgnoringCase('laravel', (string) $response->getContent());
    }
}
