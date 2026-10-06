<?php

namespace App\Services\Pickup;

use App\Enums\PickupIssueCode;
use App\Enums\PickupMethod;
use App\Enums\PickupMode;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationPickupMethod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The pickup options of one restaurant location for one cart at one instant — the answer of
 * PickupAvailabilityService. A snapshot: capacity, the clock and the restaurant move on, so every option is
 * validated again when it is chosen and once more before an order exists. Nothing in it is a reservation.
 */
final readonly class PickupAvailability
{
    public const BLOCKING = 'BLOCKING';

    public const INFO = 'INFO';

    /**
     * @param  list<RestaurantLocationPickupMethod>  $methods  the methods the location offers
     * @param  array{enabled: bool, available: bool, pickup_at: CarbonImmutable|null, ready_in_minutes: int|null, reason: PickupIssueCode|null, recommended: bool}  $asap
     * @param  array{enabled: bool, interval_minutes: int|null, horizon_minutes: int|null, horizon_ends_at: CarbonImmutable|null, truncated: bool}  $scheduled
     * @param  list<PickupSlot>  $slots
     * @param  array{mode: PickupMode, pickup_at: CarbonImmutable, slot_key: string|null, basis: string}|null  $recommended
     * @param  array{status: string, arrival_at: CarbonImmutable|null, requires_refresh: bool, source: string|null}|null  $journey
     * @param  list<array{code: string, severity: string, message: string, details: array<string, mixed>}>  $issues
     */
    public function __construct(
        public RestaurantLocation $location,
        public string $timezone,
        public CarbonImmutable $generatedAt,
        public ?int $cartVersion,
        public int $settingsVersion,
        public bool $pickupEnabled,
        public array $methods,
        public ?PickupMethod $method,
        public ?PreparationEstimate $preparation,
        public ?CarbonImmutable $kitchenReadyAt,
        public array $asap,
        public array $scheduled,
        public array $slots,
        public ?array $recommended,
        public ?array $journey,
        public array $issues,
        public CarbonImmutable $revalidateAfter,
    ) {}

    /**
     * The reason nothing can be chosen at all, if there is one.
     *
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}|null
     */
    public function blockingIssue(): ?array
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === self::BLOCKING) {
                return $issue;
            }
        }

        return null;
    }

    public function isBlocked(): bool
    {
        return $this->blockingIssue() !== null;
    }

    /**
     * The offered slot starting at that instant — full slots included, so the caller can tell "full" from "not a slot".
     */
    public function slotAt(CarbonInterface $start): ?PickupSlot
    {
        $timestamp = $start->getTimestamp();
        foreach ($this->slots as $slot) {
            if ($slot->startsAt->getTimestamp() === $timestamp) {
                return $slot;
            }
        }

        return null;
    }

    public function firstAvailableSlot(): ?PickupSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->available) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The earliest time anything can be collected: the ASAP time, or the first slot with room.
     */
    public function earliestPickupAt(): ?CarbonImmutable
    {
        if ($this->asap['available']) {
            return $this->asap['pickup_at'];
        }

        return $this->firstAvailableSlot()?->startsAt;
    }

    public function offers(PickupMethod $method): bool
    {
        foreach ($this->methods as $offered) {
            if ($offered->method === $method) {
                return true;
            }
        }

        return false;
    }

    public function availableSlotCount(): int
    {
        return count(array_filter($this->slots, fn (PickupSlot $s): bool => $s->available));
    }
}
