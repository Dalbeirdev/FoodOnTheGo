<?php

namespace App\Services\Journey;

use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantAvailability;

/**
 * One page of route-based discovery: the restaurants with their route context, the bounded total and the
 * search metadata a client needs to explain the result (what was searched, when, whether it was cached, why
 * it is empty).
 */
final readonly class DiscoveryResult
{
    /**
     * @param  list<array{restaurant: RestaurantLocation, candidate: array<string, mixed>, arrival: RestaurantAvailability}>  $items
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public array $meta,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
