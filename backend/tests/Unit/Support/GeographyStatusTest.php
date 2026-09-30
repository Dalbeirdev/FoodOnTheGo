<?php

namespace Tests\Unit\Support;

use App\Enums\CityStatus;
use App\Enums\CoverageStatus;
use App\Enums\MarketStatus;
use App\Enums\RegionStatus;
use PHPUnit\Framework\TestCase;

class GeographyStatusTest extends TestCase
{
    public function test_every_transition_table_is_closed_over_its_own_cases(): void
    {
        foreach ([MarketStatus::class, RegionStatus::class, CityStatus::class, CoverageStatus::class] as $enum) {
            $values = array_column($enum::cases(), 'value');

            $this->assertEqualsCanonicalizing($values, array_keys($enum::transitions()), $enum);
            foreach ($enum::transitions() as $from => $targets) {
                $this->assertNotContains($from, $targets, "{$enum}: {$from} → itself");
                $this->assertSame([], array_diff($targets, $values), "{$enum}: {$from}");
            }
        }
    }

    public function test_which_states_serve_customers(): void
    {
        $serving = fn (string $enum): array => array_column(array_filter($enum::cases(), fn ($s) => $s->servesCustomers()), 'value');

        $this->assertSame(['PILOT', 'ACTIVE'], $serving(MarketStatus::class));
        $this->assertSame(['PILOT', 'ACTIVE'], $serving(RegionStatus::class));
        $this->assertSame(['PILOT', 'ACTIVE'], $serving(CityStatus::class));
        $this->assertSame(['ACTIVE'], $serving(CoverageStatus::class), 'TESTING is internal');
    }

    public function test_retired_states_can_only_be_reopened_through_the_first_state(): void
    {
        $this->assertFalse(MarketStatus::Closed->canBecome(MarketStatus::Active));
        $this->assertTrue(MarketStatus::Closed->canBecome(MarketStatus::Draft));
        $this->assertFalse(CityStatus::Unavailable->canBecome(CityStatus::Active));
        $this->assertFalse(CoverageStatus::Disabled->canBecome(CoverageStatus::Active));
        $this->assertFalse(RegionStatus::Disabled->canBecome(RegionStatus::Pilot));
        $this->assertFalse(MarketStatus::Active->canBecome(MarketStatus::Draft));
    }

    public function test_a_reason_is_needed_exactly_when_service_is_taken_away(): void
    {
        $this->assertTrue(CoverageStatus::Active->needsReasonToBecome(CoverageStatus::Paused));
        $this->assertTrue(CoverageStatus::Active->needsReasonToBecome(CoverageStatus::Testing));
        $this->assertTrue(CityStatus::Pilot->needsReasonToBecome(CityStatus::Unavailable));
        $this->assertFalse(CityStatus::Pilot->needsReasonToBecome(CityStatus::Active));
        $this->assertFalse(CoverageStatus::Paused->needsReasonToBecome(CoverageStatus::Active));
        $this->assertFalse(RegionStatus::Planned->needsReasonToBecome(RegionStatus::Disabled));
    }
}
