<?php

namespace Tests\Unit\Support;

use App\Enums\DistanceUnit;
use App\Support\Distance;
use App\Support\Geo\Geo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DistanceTest extends TestCase
{
    public function test_distance_is_held_in_metres_and_converted_explicitly(): void
    {
        $distance = Distance::fromKilometres(250);

        $this->assertSame(250000.0, $distance->metres);
        $this->assertSame(250.0, $distance->in(DistanceUnit::Metric));
        $this->assertEqualsWithDelta(155.34, $distance->in(DistanceUnit::Imperial), 0.01);
    }

    public function test_negative_distances_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Distance(-1);
    }

    public function test_point_sql_uses_srid_4326_with_longitude_first(): void
    {
        [$sql, $bindings] = Geo::pointSql(latitude: 28.6280, longitude: 77.3649);

        $this->assertSame(4326, Geo::SRID);
        $this->assertStringContainsString('4326)::geography', $sql);
        $this->assertSame([77.3649, 28.6280], $bindings);
    }

    public function test_coordinates_outside_wgs84_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Geo::pointSql(latitude: 91, longitude: 77);
    }
}
