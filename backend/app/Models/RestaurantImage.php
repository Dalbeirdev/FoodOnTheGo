<?php

namespace App\Models;

use App\Enums\RestaurantImageStatus;
use App\Enums\RestaurantImageType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata of a restaurant image (logo, cover, gallery) and who owns it. An image always belongs to an
 * organization and usually to one of its locations; staff may manage it only through a location they are
 * authorised for. This module stores no file: `path` is a storage key / site-relative path.
 */
#[Fillable(['alt_text', 'display_order'])]
class RestaurantImage extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<RestaurantLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => RestaurantImageType::class, 'status' => RestaurantImageStatus::class, 'display_order' => 'integer', 'width' => 'integer', 'height' => 'integer', 'size_bytes' => 'integer'];
    }
}
