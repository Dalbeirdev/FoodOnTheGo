<?php

namespace App\Services\Analytics;

/**
 * WHAT a report may read (Module 38) — decided by the controller from the caller's permissions, never from a
 * request parameter alone. An administrator's scope is one or more markets; a restaurant member's scope is the
 * locations they may read. Every query of the reporting services is narrowed by it, and it is part of every cache key.
 */
final readonly class AnalyticsScope
{
    /**
     * @param  list<int>  $marketIds  the markets the report covers (never empty)
     * @param  list<int>|null  $locationIds  null = every location of those markets; a list = only these
     * @param  array<string, mixed>  $label  what the answer says about its scope (names, never internal ids)
     */
    public function __construct(
        public string $audience,
        public array $marketIds,
        public ?array $locationIds,
        public string $timezone,
        public string $currency,
        public array $label,
        public bool $includesUnassigned = false,
        public bool $finance = false,
        public bool $support = false,
    ) {}

    public function forLocations(): bool
    {
        return $this->locationIds !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function cacheKey(): array
    {
        $markets = $this->marketIds;
        sort($markets);
        $locations = $this->locationIds;
        if ($locations !== null) {
            sort($locations);
        }

        return ['audience' => $this->audience, 'markets' => $markets, 'locations' => $locations, 'unassigned' => $this->includesUnassigned, 'finance' => $this->finance, 'support' => $this->support, 'tz' => $this->timezone];
    }
}
