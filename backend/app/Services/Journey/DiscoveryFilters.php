<?php

namespace App\Services\Journey;

use App\Enums\JourneySort;

/**
 * What a client may vary on GET /journeys/{journey}/restaurants. Candidate-set filters (corridor, cuisines,
 * features, pickup method, text) change which restaurants are shortlisted and sent to the routing provider;
 * result filters (detour limit, open at arrival) and the sort are applied to the cached candidate set.
 *
 * @phpstan-type Validated array{sort?: string, corridor_width_meters?: int, max_detour_minutes?: int, open_at_arrival?: bool|string, cuisine?: string, feature?: string, pickup_method?: string, q?: string, page?: array{number?: int, size?: int}}
 */
final readonly class DiscoveryFilters
{
    /**
     * @param  list<string>  $cuisines
     * @param  list<string>  $features
     */
    public function __construct(
        public JourneySort $sort,
        public ?int $corridorWidthMeters,
        public ?int $maxDetourMinutes,
        public bool $openAtArrival,
        public array $cuisines,
        public array $features,
        public ?string $pickupMethod,
        public ?string $query,
        public int $page,
        public int $pageSize,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated
     * @param  array{page_size: int, max_page_size: int}  $policy
     */
    public static function fromInput(array $input, array $policy): self
    {
        $codes = fn (?string $value): array => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $v): bool => $v !== '')));
        $open = $input['open_at_arrival'] ?? false;

        return new self(
            JourneySort::from($input['sort'] ?? JourneySort::Recommended->value),
            isset($input['corridor_width_meters']) ? (int) $input['corridor_width_meters'] : null,
            isset($input['max_detour_minutes']) ? (int) $input['max_detour_minutes'] : null,
            in_array($open, [true, 'true', '1', 1], true),
            $codes($input['cuisine'] ?? null),
            $codes($input['feature'] ?? null),
            isset($input['pickup_method']) ? strtoupper((string) $input['pickup_method']) : null,
            isset($input['q']) && trim((string) $input['q']) !== '' ? mb_substr(trim((string) $input['q']), 0, 80) : null,
            max(1, (int) ($input['page']['number'] ?? 1)),
            max(1, min($policy['max_page_size'], (int) ($input['page']['size'] ?? $policy['page_size']))),
        );
    }

    /**
     * Identity of the candidate set this request describes (everything that changes the PostGIS shortlist).
     */
    public function candidateKey(int $corridorWidthMeters): string
    {
        return hash('sha256', json_encode([$corridorWidthMeters, $this->cuisines, $this->features, $this->pickupMethod, mb_strtolower((string) $this->query)]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $corridorWidthMeters, int $maxDetourMinutes): array
    {
        return [
            'sort' => $this->sort->value,
            'corridor_width_meters' => $corridorWidthMeters,
            'max_detour_minutes' => $maxDetourMinutes,
            'open_at_arrival' => $this->openAtArrival,
            'cuisine' => $this->cuisines,
            'feature' => $this->features,
            'pickup_method' => $this->pickupMethod,
            'q' => $this->query,
        ];
    }
}
