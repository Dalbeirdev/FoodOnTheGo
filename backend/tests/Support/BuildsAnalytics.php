<?php

namespace Tests\Support;

use App\Enums\MarketStatus;
use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\Order;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Analytics\Aggregation\AggregationService;
use App\Services\Analytics\Pseudonymizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Module 38 — analytics fixtures: the aggregation, the two audiences' requests, behavioural events through the real
 * ingestion endpoint, and bulk sessions written straight into the event table for the funnel arithmetic.
 */
trait BuildsAnalytics
{
    use BuildsSupport;

    protected const ANALYST = [Permission::AdminAnalyticsView, Permission::AdminAnalyticsExport];

    /**
     * @param  list<string>|null  $groups
     * @return array<string, mixed>
     */
    protected function aggregate(string $trigger = 'BACKFILL', ?string $from = null, ?string $to = null, bool $dryRun = false, ?array $groups = null): array
    {
        $result = app(AggregationService::class)->run($trigger, $groups, $from, $to, null, $dryRun);
        $this->assertSame('OK', $result['status'], (string) json_encode($result));

        return $result;
    }

    /**
     * An administrator who may read analytics (and export), plus whatever else — platform-wide or for one market.
     *
     * @param  list<Permission>  $more
     */
    protected function analyst(array $more = [], ?Market $scope = null): AdminUser
    {
        return $this->adminWith([...self::ANALYST, ...$more], $scope);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function adminAnalytics(AdminUser $as, string $path, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($as);

        return $this->getJson('/api/v1/admin/analytics'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function restaurantAnalytics(RestaurantUser $as, string $path, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($as);

        return $this->getJson('/api/v1/restaurant/analytics'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * One behavioural event as an application sends it.
     *
     * @param  array<string, string>  $refs
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function event(string $name, array $refs = [], array $properties = [], ?string $at = null, ?string $id = null): array
    {
        return array_filter(['id' => $id ?? (string) Str::uuid(), 'name' => $name, 'occurred_at' => $at ?? CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'refs' => $refs, 'properties' => $properties], fn ($v): bool => $v !== []);
    }

    /**
     * Sends a batch to the ingestion endpoint — as a guest, or as the customer whose session headers are given.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    protected function sendEvents(array $events, array $headers = [], ?string $session = null, string $platform = 'WEB', array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/analytics/events', ['session' => $session ?? (string) Str::uuid(), 'platform' => $platform, 'events' => $events] + $extra, $headers);
    }

    /**
     * Sessions written straight into the event table: each reaches the given stage of the funnel, in order, one
     * minute per stage. For the arithmetic of the funnel — the ingestion endpoint has tests of its own.
     *
     * @param  array<int, string>  $checkouts  session index => checkout public id (for sessions that reach stage 7)
     * @return list<string> the session ids
     */
    protected function sessionsReaching(int $count, int $stage, string $start = '2026-10-07 10:00', string $platform = 'WEB', array $checkouts = [], ?int $customerId = null): array
    {
        $names = [1 => 'journey_search_started', 2 => 'journey_results_viewed', 3 => 'restaurant_viewed', 4 => 'menu_viewed', 5 => 'item_added_to_cart', 6 => 'pickup_selected', 7 => 'checkout_started', 8 => 'payment_started'];
        $at = CarbonImmutable::parse($start, 'Asia/Kolkata')->utc();
        $key = $customerId === null ? null : app(Pseudonymizer::class)->customer($customerId);
        $rows = [];
        $sessions = [];
        for ($i = 0; $i < $count; $i++) {
            $session = (string) Str::uuid();
            $sessions[] = $session;
            for ($s = 1; $s <= min(8, $stage); $s++) {
                $rows[] = [
                    'event_id' => (string) Str::uuid(), 'event_name' => $names[$s], 'schema_version' => 1, 'source' => 'CLIENT', 'platform' => $platform, 'occurred_at' => $at->addMinutes($s), 'received_at' => $at->addMinutes($s),
                    'session_id' => $session, 'customer_key' => $key, 'market_id' => $this->market->getKey(), 'location_id' => $s >= 3 ? $this->burgerHub->getKey() : null,
                    'checkout_ref' => $s >= 7 ? ($checkouts[$i] ?? null) : null, 'properties' => '{}',
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('analytics_events')->insert($chunk);
        }

        return $sessions;
    }

    /**
     * A paid order made by ONE session that reports every funnel step through the real ingestion endpoint, then
     * prepared, collected and completed. Returns the order and the session.
     *
     * @return array{0: Order, 1: string, 2: array<string, string>}
     */
    protected function sessionOrder(bool $complete = true, ?string $journeyId = null, string $platform = 'WEB', ?string $slot = null): array
    {
        $session = (string) Str::uuid();
        $restaurant = (string) $this->burgerHub->public_id;
        $journey = $journeyId === null ? [] : ['journey' => $journeyId];
        $this->sendEvents([$this->event('journey_search_started'), ...($journeyId === null ? [] : [$this->event('journey_results_viewed', $journey, ['results' => 1])])], [], $session, $platform)->assertStatus(202);
        if ($journeyId === null) {
            $this->sendEvents([$this->event('journey_results_viewed', ['journey' => (string) Str::uuid()], ['results' => 1])], [], $session, $platform)->assertStatus(202);
        }
        [, $headers] = $this->customerReadyForCheckout(2, ['Size' => ['Large']], $slot ?? $this->nextAnalyticsSlot(), $this->nextPhone());
        if ($journeyId !== null) {
            // The cart is linked to the journey the way route discovery links it: an arrival time and a four-minute detour.
            $this->cartModel($headers)->forceFill(['journey_public_id' => $journeyId, 'journey_summary' => [
                'origin' => 'West gate', 'destination' => 'East gate', 'restaurant_on_route' => true, 'distance_from_route_meters' => 40, 'detour' => ['duration_seconds' => 240, 'distance_meters' => 1800],
                'estimated_arrival_at' => now()->addHour()->utc()->toIso8601String(), 'provider_mode' => 'development', 'linked_at' => now()->utc()->toIso8601String(),
            ]])->save();
        }
        $this->sendEvents([
            $this->event('restaurant_viewed', ['restaurant' => $restaurant] + $journey, ['origin' => 'JOURNEY']), $this->event('menu_viewed', ['restaurant' => $restaurant] + $journey),
            $this->event('item_added_to_cart', ['restaurant' => $restaurant], ['quantity' => 2]), $this->event('pickup_selected', ['restaurant' => $restaurant], ['mode' => 'SCHEDULED', 'method' => 'COUNTER']),
        ], $headers, $session, $platform)->assertStatus(202)->assertJsonPath('accepted', 4);
        $draft = $this->openCheckout($headers)->assertCreated()->json('checkout');
        $ready = $this->confirmCheckout($headers, $draft)->assertOk()->json('checkout');
        $this->sendEvents([$this->event('checkout_started', ['restaurant' => $restaurant, 'checkout' => $ready['id']])], $headers, $session, $platform)->assertStatus(202)->assertJsonPath('accepted', 1);
        $payment = $this->startPayment($headers, $ready)->assertCreated()->json('payment');
        $this->sendEvents([$this->event('payment_started', ['checkout' => $ready['id']], ['method' => 'UPI'])], $headers, $session, $platform)->assertStatus(202)->assertJsonPath('accepted', 1);
        $verified = $this->completePayment($headers, $payment['id'], $this->proofOf($payment['id'], $this->providerPays($payment['id'])))->assertOk()->json('payment');
        $number = $verified['fulfillment']['order_number'];
        if ($complete) {
            $this->orderStaff();
            foreach (['accept', 'start-preparing', 'ready'] as $step) {
                $this->step($number, $step)->assertOk();
            }
            $this->verifyQr($this->credentialOf($headers, $number)['qr_payload'])->assertOk();
        }

        return [$this->orderModel($number), $session, $headers];
    }

    /**
     * Writes a real answer to a folder when ANALYTICS_SAMPLES names one — the examples the documentation and the
     * clients are written against. Without the variable nothing is written.
     */
    protected function sample(string $name, mixed $answer): void
    {
        $dir = getenv('ANALYTICS_SAMPLES');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/').'/'.$name.'.json', json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
    }

    /**
     * A COPY of a real order as another order: another day, market, restaurant, currency or state. Written straight
     * into the orders table — for the arithmetic of days, time zones and currencies, where forty orders through the
     * real API would only be slow. Everything an order must be consistent in stays consistent.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function orderCopy(Order $source, array $overrides = []): int
    {
        $row = (array) DB::table('orders')->where('id', $source->getKey())->first();
        unset($row['id']);
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = implode('', array_map(fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 6)));

        return (int) DB::table('orders')->insertGetId(array_merge($row, [
            'public_id' => (string) Str::uuid(), 'order_number' => 'FOTG-20261007-'.$suffix, 'checkout_session_id' => null, 'checkout_public_id' => (string) Str::uuid(),
            'payment_attempt_id' => null, 'payment_public_id' => (string) Str::uuid(), 'pickup_reservation_id' => null,
        ], $overrides));
    }

    /**
     * A second market with its own currency and a time zone that has daylight saving time, and one restaurant in it.
     *
     * @return array{0: Market, 1: RestaurantLocation}
     */
    protected function secondMarket(string $country = 'US', string $currency = 'USD', string $timezone = 'America/New_York'): array
    {
        $market = Market::factory()->create(['country_code' => $country, 'slug' => 'market-'.strtolower($country), 'name' => 'Market '.$country, 'status' => MarketStatus::Active,
            'default_currency' => $currency, 'supported_currencies' => [$currency], 'default_timezone' => $timezone]);
        $location = $this->location($this->organization('Diner Group '.$country), 'Diner '.$country, ['market_id' => $market->getKey(), 'currency' => $currency, 'timezone' => $timezone], 28.58, 77.38);

        return [$market, $location];
    }

    private int $analyticsSlot = 0;

    private function nextAnalyticsSlot(): string
    {
        $slots = ['13:00', '13:15', '13:30', '13:45', '14:00', '14:15', '14:30', '14:45', '15:00', '15:15', '15:30', '15:45'];

        return $slots[$this->analyticsSlot++ % count($slots)];
    }
}
