<?php

namespace App\Listeners;

use App\Events\MarketGeographyChanged;
use App\Models\ServiceArea;
use App\Services\Restaurant\LocationGeography;
use App\Services\Restaurant\RestaurantCatalog;

/**
 * Keeps the stored "service area of a restaurant location" in step with the geography: when a service area
 * is added, redrawn or re-prioritised, every location of that market is resolved again by PostGIS.
 * (Customer visibility never depends on this stored value — it is decided from the geometry on each request.)
 */
class ReassignRestaurantServiceAreas
{
    public function __construct(private readonly LocationGeography $geography, private readonly RestaurantCatalog $catalog) {}

    public function handle(MarketGeographyChanged $event): void
    {
        if ($event->record instanceof ServiceArea) {
            $this->geography->reassignServiceAreas($event->market);
        }

        $this->catalog->flush();
    }
}
