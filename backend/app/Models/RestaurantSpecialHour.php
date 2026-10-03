<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Exception for one local calendar date of a location: closed all day, or different opening periods.
 * `date` is a plain date in the time zone of the location — always written and compared as "YYYY-MM-DD"
 * (it has no cast on purpose: a date is not an instant and must never be shifted between zones).
 */
#[Fillable(['public_note', 'internal_note'])]
class RestaurantSpecialHour extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return HasMany<RestaurantSpecialHourPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(RestaurantSpecialHourPeriod::class, 'special_hour_id')->orderBy('sequence');
    }

    /**
     * The date as "YYYY-MM-DD".
     */
    public function day(): string
    {
        return substr((string) $this->getAttribute('date'), 0, 10);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }
}
