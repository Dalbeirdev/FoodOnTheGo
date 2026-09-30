<?php

namespace App\Services\Geo;

use App\Support\Distance;
use App\Support\Geo\Location;
use Illuminate\Support\Facades\DB;

/**
 * Straight-line (geodesic) distance between two locations, computed by PostGIS on the WGS84 spheroid and
 * returned in metres. Driving distance and detours come from the routing provider, not from here.
 */
final class DistanceService
{
    public function between(Location $from, Location $to): Distance
    {
        [$a, $aBindings] = $from->pointSql();
        [$b, $bBindings] = $to->pointSql();

        return new Distance((float) DB::selectOne("select ST_Distance({$a}::geography, {$b}::geography) as m", [...$aBindings, ...$bBindings])->m);
    }
}
