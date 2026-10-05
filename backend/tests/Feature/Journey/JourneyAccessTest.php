<?php

namespace Tests\Feature\Journey;

use App\Enums\CustomerStatus;
use App\Models\Journey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsJourneys;
use Tests\TestCase;

/**
 * A journey belongs to whoever planned it: the customer, or the holder of the guest token. It expires, and
 * expired contexts are pruned — there is no journey history.
 */
class JourneyAccessTest extends TestCase
{
    use BuildsJourneys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    }

    public function test_a_guest_journey_is_readable_only_with_its_token(): void
    {
        $plan = $this->planGuest();
        $url = '/api/v1/journeys/'.$plan['id'];

        $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'journey_not_found');
        $this->getJson($url, ['X-Journey-Token' => str_repeat('0', 64)])->assertNotFound();
        $this->getJson($url, ['X-Journey-Token' => substr($plan['token'], 0, 63).($plan['token'][63] === 'f' ? '0' : 'f')])->assertNotFound();
        $this->getJson($url, $plan['headers'])->assertOk();
        $this->getJson($url.'/restaurants')->assertNotFound();
        $this->getJson($url.'/restaurants', $plan['headers'])->assertOk();

        // A signed-in customer does not own a guest journey either.
        $this->actingAsPrincipal($this->customer($this->market));
        $this->getJson($url)->assertNotFound();
        $this->getJson($url, $plan['headers'])->assertOk(); // but the token still works, whoever presents it
    }

    public function test_a_customer_journey_is_readable_by_that_customer_only(): void
    {
        $a = $this->customer($this->market, ['phone_e164' => '+919876543210']);
        $b = $this->customer($this->market, ['phone_e164' => '+919876543211']);

        $this->actingAsPrincipal($a);
        $id = $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated()->json('id');
        $this->getJson('/api/v1/journeys/'.$id)->assertOk();
        $this->getJson('/api/v1/journeys/'.$id.'/restaurants')->assertOk();

        $this->actingAsPrincipal($b);
        $this->getJson('/api/v1/journeys/'.$id)->assertNotFound();
        $this->getJson('/api/v1/journeys/'.$id.'/restaurants')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/journeys/'.$id)->assertNotFound();
        $this->getJson('/api/v1/journeys/'.$id, ['X-Journey-Token' => str_repeat('a', 64)])->assertNotFound();

        $this->getJson('/api/v1/journeys/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->getJson('/api/v1/journeys/not-a-journey')->assertNotFound();
        $this->assertSame(1, Journey::query()->count());
    }

    public function test_journeys_expire_and_expired_contexts_are_pruned(): void
    {
        $plan = $this->planGuest();
        $url = '/api/v1/journeys/'.$plan['id'];
        $this->getJson($url, $plan['headers'])->assertOk()->assertJsonPath('status', 'ACTIVE');

        $this->travel(31)->minutes();
        $this->getJson($url, $plan['headers'])->assertStatus(410)->assertJsonPath('error.code', 'journey_expired');
        $this->getJson($url.'/restaurants', $plan['headers'])->assertStatus(410);

        $this->artisan('journeys:prune')->expectsOutputToContain('Removed 0')->assertSuccessful();
        $this->assertSame(1, Journey::query()->count(), 'kept during the grace period so clients get "expired", not "not found"');

        $this->travel(61)->minutes();
        $this->artisan('journeys:prune')->expectsOutputToContain('Removed 1')->assertSuccessful();
        $this->assertSame(0, Journey::query()->count());
        $this->getJson($url, $plan['headers'])->assertNotFound();
    }

    public function test_a_browser_may_send_the_guest_journey_token_header_from_a_configured_origin_only(): void
    {
        $origin = config('cors.allowed_origins')[0];
        $plan = $this->planGuest();
        $url = '/api/v1/journeys/'.$plan['id'].'/restaurants';

        // The preflight a browser sends before a GET that carries X-Journey-Token.
        $preflight = $this->call('OPTIONS', $url, server: ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-journey-token,x-request-id']);
        $preflight->assertNoContent();
        $preflight->assertHeader('Access-Control-Allow-Origin', $origin);
        $this->assertStringContainsString('x-journey-token', strtolower((string) $preflight->headers->get('Access-Control-Allow-Headers')));

        $this->getJson($url, $plan['headers'] + ['Origin' => $origin])->assertOk()->assertHeader('Access-Control-Allow-Origin', $origin);

        $foreign = $this->call('OPTIONS', $url, server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-journey-token']);
        $this->assertNull($foreign->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_customer_token_that_cannot_be_used_is_refused_even_though_guests_are_welcome(): void
    {
        $this->actingAsPrincipal($this->customer($this->market, ['status' => CustomerStatus::Suspended]));
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertForbidden()->assertJsonPath('error.code', 'account_not_active');
        $this->getJson('/api/v1/places/search?q=delhi')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/journeys/plan', $this->planBody())->assertCreated();
    }
}
