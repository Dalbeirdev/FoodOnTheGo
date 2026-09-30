<?php

namespace App\Http\Resources;

use App\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Market
 */
class MarketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'country_code' => $this->country_code,
            'name' => $this->name,
            'status' => $this->status->value,
            'default_currency' => $this->default_currency,
            'supported_currencies' => $this->supported_currencies,
            'default_locale' => $this->default_locale,
            'supported_locales' => $this->supported_locales,
            'timezone_strategy' => $this->timezone_strategy,
            'default_timezone' => $this->default_timezone,
            'distance_unit' => $this->distance_unit->value,
            'phone_country_code' => $this->phone_country_code,
            'features' => (object) $this->features,
            'launched_at' => $this->launched_at?->toIso8601String(),
        ];
    }
}
