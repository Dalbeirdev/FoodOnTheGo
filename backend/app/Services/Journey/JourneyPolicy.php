<?php

namespace App\Services\Journey;

use App\Models\Market;

/**
 * The journey policy in force for a market: the platform defaults and bounds of config/journey.php, with the
 * market's own overrides (market_configurations.journey) clamped into those bounds. One place decides the
 * corridor width, the detour limit and the candidate cap — no literal appears anywhere else.
 */
final class JourneyPolicy
{
    /**
     * @return array{corridor_width_meters: int, corridor_min_meters: int, corridor_max_meters: int, max_detour_minutes: int, max_detour_min_minutes: int, max_detour_max_minutes: int, candidate_cap: int, segment_length_meters: int, per_segment_cap: int, page_size: int, max_page_size: int}
     */
    public function forMarket(Market $market): array
    {
        $market->loadMissing('configuration');
        $override = (array) ($market->configuration?->journey ?? []);

        return [
            'corridor_width_meters' => $this->clampCorridor((int) ($override['corridor_width_meters'] ?? config('journey.corridor.default_width_meters'))),
            'corridor_min_meters' => (int) config('journey.corridor.min_width_meters'),
            'corridor_max_meters' => (int) config('journey.corridor.max_width_meters'),
            'max_detour_minutes' => $this->clampDetour((int) ($override['max_detour_minutes'] ?? config('journey.detour.default_max_minutes'))),
            'max_detour_min_minutes' => (int) config('journey.detour.min_max_minutes'),
            'max_detour_max_minutes' => (int) config('journey.detour.max_max_minutes'),
            'candidate_cap' => $this->clamp((int) ($override['candidate_cap'] ?? config('journey.candidates.shortlist_cap')), 5, (int) config('journey.candidates.shortlist_cap_max')),
            'segment_length_meters' => max(1000, (int) config('journey.candidates.segment_length_meters')),
            'per_segment_cap' => max(1, (int) config('journey.candidates.per_segment_cap')),
            'page_size' => max(1, (int) config('journey.pagination.page_size')),
            'max_page_size' => max(1, (int) config('journey.pagination.max_page_size')),
        ];
    }

    public function clampCorridor(int $meters): int
    {
        return $this->clamp($meters, (int) config('journey.corridor.min_width_meters'), (int) config('journey.corridor.max_width_meters'));
    }

    public function clampDetour(int $minutes): int
    {
        return $this->clamp($minutes, (int) config('journey.detour.min_max_minutes'), (int) config('journey.detour.max_max_minutes'));
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
