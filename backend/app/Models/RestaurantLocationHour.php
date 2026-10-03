<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekly opening period of a location: wall-clock times in the time zone of the location.
 * `closes_at` at or before `opens_at` runs past midnight (equal = 24 hours); the period belongs to the day
 * on which it opens. day_of_week: 0 = Sunday … 6 = Saturday.
 */
class RestaurantLocationHour extends Model
{
    use StoresUtcTimestamps;

    public const OPENING = 'OPENING';

    protected $guarded = [];

    /**
     * "HH:MM" (the database returns seconds as well).
     */
    public function opens(): string
    {
        return substr((string) $this->opens_at, 0, 5);
    }

    public function closes(): string
    {
        return substr((string) $this->closes_at, 0, 5);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'sequence' => 'integer'];
    }
}
