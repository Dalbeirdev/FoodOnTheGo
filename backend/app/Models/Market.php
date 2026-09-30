<?php

namespace App\Models;

use App\Enums\DistanceUnit;
use App\Enums\MarketStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Database\Factories\MarketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'country_code', 'slug', 'name', 'status', 'default_currency', 'supported_currencies', 'default_locale',
    'supported_locales', 'timezone_strategy', 'default_timezone', 'distance_unit', 'phone_country_code',
    'features', 'launched_at',
])]
class Market extends Model
{
    /** @use HasFactory<MarketFactory> */
    use HasFactory, HasPublicId, StoresUtcTimestamps;

    /**
     * Markets that may serve customers (ACTIVE or PILOT).
     *
     * @param  Builder<Market>  $query
     */
    public function scopeServingCustomers(Builder $query): void
    {
        $query->whereIn('status', [MarketStatus::Active->value, MarketStatus::Pilot->value]);
    }

    public function featureEnabled(string $key): bool
    {
        return ($this->features[$key] ?? false) === true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MarketStatus::class,
            'distance_unit' => DistanceUnit::class,
            'supported_currencies' => 'array',
            'supported_locales' => 'array',
            'features' => 'array',
            'launched_at' => 'datetime',
        ];
    }
}
