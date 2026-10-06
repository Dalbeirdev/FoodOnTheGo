<?php

namespace Tests\Feature\Pickup;

use App\Services\Pickup\PickupSlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPickup;
use Tests\TestCase;

/**
 * A slot key names a slot of ONE restaurant location. It proves nothing about availability, and a key that was
 * altered, made up or issued for another restaurant names nothing.
 */
class PickupSlotKeyTest extends TestCase
{
    use BuildsPickup, RefreshDatabase;

    public function test_a_key_round_trips_and_is_bound_to_its_restaurant(): void
    {
        $this->setUpPickupWorld();
        [$pizza] = $this->otherRestaurant();
        $start = CarbonImmutable::parse('2026-10-07 13:15', 'Asia/Kolkata');

        $key = PickupSlotKey::make($this->burgerHub, $start, 15);

        $this->assertMatchesRegularExpression('/^ps1_[0-9a-z]+_15_[0-9a-f]{12}$/', $key);
        $this->assertSame($key, PickupSlotKey::make($this->burgerHub, $start->utc(), 15), 'the same instant gives the same key in any zone');
        $parsed = PickupSlotKey::parse($this->burgerHub, $key);
        $this->assertTrue($parsed['start']->equalTo($start));
        $this->assertSame(15, $parsed['interval']);

        // Another restaurant, another interval, another minute: different keys, and none opens the other's door.
        $this->assertNull(PickupSlotKey::parse($pizza, $key));
        $this->assertNotSame($key, PickupSlotKey::make($pizza, $start, 15));
        $this->assertNotSame($key, PickupSlotKey::make($this->burgerHub, $start, 30));
        $this->assertNotSame($key, PickupSlotKey::make($this->burgerHub, $start->addMinute(), 15));
    }

    public function test_anything_that_is_not_a_key_of_this_backend_is_nothing(): void
    {
        $this->setUpPickupWorld();
        $key = PickupSlotKey::make($this->burgerHub, CarbonImmutable::parse('2026-10-07 13:15', 'Asia/Kolkata'), 15);
        [$prefix, $start, $interval, $tag] = explode('_', $key);

        foreach ([
            null, 42, [], '', 'ps1', '2026-10-07T13:15:00+05:30', '1791359100', "{$prefix}_{$start}_{$interval}", "{$prefix}_{$start}_{$interval}_", "ps2_{$start}_{$interval}_{$tag}",
            "{$prefix}_{$start}_30_{$tag}", "{$prefix}_{$start}_0_{$tag}", "{$prefix}_{$start}_{$interval}_".strrev($tag), "{$prefix}_{$start}_{$interval}_".strtoupper($tag),
            $key.' ', " {$key}", $key."\n", "{$key}_extra", str_repeat('a', 500),
        ] as $bad) {
            $this->assertNull(PickupSlotKey::parse($this->burgerHub, $bad), var_export($bad, true));
        }

        // The tag depends on the application key: a key issued by another installation does not validate here.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNull(PickupSlotKey::parse($this->burgerHub, $key));
    }
}
