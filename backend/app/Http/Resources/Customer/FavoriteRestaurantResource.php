<?php

namespace App\Http\Resources\Customer;

use App\Http\Resources\Restaurant\PublicRestaurantResource;
use App\Models\CustomerFavoriteLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One favorite of the customer (Module 25): the restaurant as customers see it when they may see it right
 * now, otherwise only its name with `available: false` (the preference is kept, nothing else is revealed).
 *
 * @mixin CustomerFavoriteLocation
 */
class FavoriteRestaurantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerFavoriteLocation $favorite */
        $favorite = $this->resource;
        $location = $favorite->relationLoaded('location') ? $favorite->getRelation('location') : null;
        $summary = $favorite->getAttribute('summary');

        return [
            'restaurant_id' => $summary?->public_id,
            'slug' => $summary?->slug,
            'name' => $summary?->name,
            'added_at' => $favorite->created_at?->toIso8601String(),
            'available' => $location !== null,
            'restaurant' => $location === null ? null : new PublicRestaurantResource($location),
        ];
    }
}
