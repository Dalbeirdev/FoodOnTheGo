<?php

namespace App\Services\Pickup;

use App\Enums\PickupCapacityState;
use App\Enums\PickupIssueCode;
use Carbon\CarbonImmutable;

/**
 * One pickup slot of an availability answer: [startsAt, endsAt) as instants. It is an OPTION that was valid when
 * the answer was calculated — not a row in the database, and not a reservation.
 */
final readonly class PickupSlot
{
    public function __construct(
        public string $key,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $available,
        public PickupCapacityState $capacity,
        public ?PickupIssueCode $reason = null,
        public bool $recommended = false,
    ) {}

    public function recommend(): self
    {
        return new self($this->key, $this->startsAt, $this->endsAt, $this->available, $this->capacity, $this->reason, true);
    }
}
