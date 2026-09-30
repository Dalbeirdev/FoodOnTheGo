<?php

namespace App\Models;

use App\Enums\RegionStatus;
use App\Enums\RegionType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSpatialColumns;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * State, union territory, province … of a market. Reference geography; `status` says whether FoodOnTheGo
 * operates there. Market and status are never mass-assignable.
 */
#[Fillable(['code', 'name', 'type'])]
class MarketRegion extends Model
{
    use HasPublicId, HasSpatialColumns, StoresUtcTimestamps;

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'region_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => RegionStatus::class, 'type' => RegionType::class];
    }
}
