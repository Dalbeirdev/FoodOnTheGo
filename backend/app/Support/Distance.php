<?php

namespace App\Support;

use App\Enums\DistanceUnit;
use InvalidArgumentException;

/**
 * A distance in metres — the only unit used for storage and geospatial calculations.
 * Kilometres and miles exist only as presentation conversions chosen by the market's unit system.
 */
final readonly class Distance
{
    private const METRES_PER_MILE = 1609.344;

    public function __construct(public float $metres)
    {
        if ($metres < 0) {
            throw new InvalidArgumentException('Distance cannot be negative.');
        }
    }

    public static function fromKilometres(float $kilometres): self
    {
        return new self($kilometres * 1000);
    }

    public function kilometres(): float
    {
        return $this->metres / 1000;
    }

    public function miles(): float
    {
        return $this->metres / self::METRES_PER_MILE;
    }

    /**
     * Value in the market's long-distance unit (kilometres for metric, miles for imperial).
     */
    public function in(DistanceUnit $unit): float
    {
        return $unit === DistanceUnit::Metric ? $this->kilometres() : $this->miles();
    }
}
