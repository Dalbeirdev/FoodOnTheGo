<?php

namespace App\Services\Journey\Data;

use App\Enums\DetourStatus;

/**
 * The actual detour for one waypoint: origin → waypoint → destination minus the base route, plus the first leg
 * (origin → waypoint) the arrival estimate is built on. Null figures mean the provider did not answer — they
 * are never replaced by an estimate.
 */
final readonly class DetourResult
{
    public function __construct(
        public string $key,
        public DetourStatus $status,
        public ?int $detourSeconds = null,
        public ?int $detourDistanceMeters = null,
        public ?int $legToWaypointSeconds = null,
        public ?int $legToWaypointMeters = null,
    ) {}

    public static function ok(string $key, int $detourSeconds, int $detourDistanceMeters, int $legSeconds, int $legMeters): self
    {
        return new self($key, DetourStatus::Ok, max(0, $detourSeconds), max(0, $detourDistanceMeters), max(0, $legSeconds), max(0, $legMeters));
    }

    public static function noRoute(string $key): self
    {
        return new self($key, DetourStatus::NoRoute);
    }

    public static function failed(string $key): self
    {
        return new self($key, DetourStatus::Failed);
    }

    /**
     * Plain form for the cache (no PHP class is unserialized from the cache — config/cache.php).
     *
     * @return array{key: string, status: string, detour_seconds: int|null, detour_meters: int|null, leg_seconds: int|null, leg_meters: int|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'status' => $this->status->value, 'detour_seconds' => $this->detourSeconds, 'detour_meters' => $this->detourDistanceMeters,
            'leg_seconds' => $this->legToWaypointSeconds, 'leg_meters' => $this->legToWaypointMeters,
        ];
    }

    /**
     * @param  array<string, mixed>  $row  a row written by toArray()
     */
    public static function fromArray(array $row): ?self
    {
        $status = DetourStatus::tryFrom((string) ($row['status'] ?? ''));
        if ($status === null || ! is_string($row['key'] ?? null)) {
            return null;
        }
        $int = fn (string $field): ?int => isset($row[$field]) ? (int) $row[$field] : null;

        return new self($row['key'], $status, $int('detour_seconds'), $int('detour_meters'), $int('leg_seconds'), $int('leg_meters'));
    }

    public function usable(): bool
    {
        return $this->status === DetourStatus::Ok && $this->detourSeconds !== null && $this->legToWaypointSeconds !== null;
    }
}
