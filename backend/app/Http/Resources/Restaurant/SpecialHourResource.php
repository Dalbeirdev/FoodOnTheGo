<?php

namespace App\Http\Resources\Restaurant;

use App\Models\RestaurantSpecialHour;
use App\Models\RestaurantSpecialHourPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One special date of a location, for the people who manage it (the internal note is theirs alone).
 *
 * @mixin RestaurantSpecialHour
 */
class SpecialHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'date' => $this->day(),
            'is_closed' => $this->is_closed,
            'periods' => $this->periods->map(fn (RestaurantSpecialHourPeriod $p): array => ['opens_at' => $p->opens(), 'closes_at' => $p->closes()])->values()->all(),
            'public_note' => $this->public_note,
            'internal_note' => $this->internal_note,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
