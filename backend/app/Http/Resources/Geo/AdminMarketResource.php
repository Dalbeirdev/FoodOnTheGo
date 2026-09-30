<?php

namespace App\Http\Resources\Geo;

use App\Http\Resources\MarketResource;
use Illuminate\Http\Request;

/**
 * A market as administrators see it: the public fields plus what is needed to edit it safely.
 */
class AdminMarketResource extends MarketResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'serving_customers' => $this->status->servesCustomers(),
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
