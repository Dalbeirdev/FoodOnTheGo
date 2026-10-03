<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One opening period of a special date — same wall-clock semantics as a weekly period.
 */
class RestaurantSpecialHourPeriod extends Model
{
    public $timestamps = false;

    protected $guarded = [];

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
        return ['sequence' => 'integer'];
    }
}
