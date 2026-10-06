<?php

namespace Tests\Unit\Pickup;

use App\Services\Pickup\PickupSlotGenerator;
use App\Services\Restaurant\Schedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The slot grid and the opening intervals — pure calculation, no database and no clock.
 */
class PickupSlotGeneratorTest extends TestCase
{
    private const ZONE = 'Asia/Kolkata';

    /**
     * @param  list<array{0: string, 1: string}>  $periods  the same every day
     * @param  array<string, array{closed: bool, periods: list<array{0: int, 1: int}>, note: string|null}>  $special
     */
    private function schedule(array $periods, array $special = [], string $zone = self::ZONE): Schedule
    {
        $day = array_map(fn (array $p): array => [Schedule::minutes($p[0]), Schedule::minutes($p[1])], $periods);

        return new Schedule($zone, array_fill(0, 7, $day), $special);
    }

    private function at(string $local, string $zone = self::ZONE): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $zone);
    }

    /**
     * @return list<string> local "d H:i" of every candidate
     */
    private function times(Schedule $schedule, int $interval, int $cutoff, string $from, string $to, int $max = 1000): array
    {
        $result = (new PickupSlotGenerator)->candidates($schedule, $interval, $cutoff, $this->at($from, $schedule->timezone), $this->at($to, $schedule->timezone), $max);

        return array_map(fn (array $slot): string => $slot[0]->setTimezone($schedule->timezone)->format('d H:i'), $result['slots']);
    }

    public function test_the_grid_counts_from_local_midnight_whatever_minute_the_restaurant_opens(): void
    {
        $this->assertSame(['07 10:00', '07 10:15', '07 10:30'], $this->times($this->schedule([['10:00', '22:00']]), 15, 0, '2026-10-07 08:00', '2026-10-07 10:30'));
        $this->assertSame(['07 10:15', '07 10:30'], $this->times($this->schedule([['10:07', '22:00']]), 15, 0, '2026-10-07 08:00', '2026-10-07 10:30'));
        $this->assertSame(['07 10:30', '07 11:00'], $this->times($this->schedule([['10:07', '22:00']]), 30, 0, '2026-10-07 08:00', '2026-10-07 11:00'));
        $this->assertSame(['07 11:00', '07 12:00'], $this->times($this->schedule([['10:07', '22:00']]), 60, 0, '2026-10-07 08:00', '2026-10-07 12:00'));
        // "Not before" in the middle of a minute or off the grid rounds up to the next grid time.
        $this->assertSame(['07 12:30'], $this->times($this->schedule([['09:00', '22:00']]), 15, 0, '2026-10-07 12:16:30', '2026-10-07 12:30'));
        $this->assertSame(['07 12:20', '07 12:25'], $this->times($this->schedule([['09:00', '22:00']]), 5, 0, '2026-10-07 12:20', '2026-10-07 12:25'));
    }

    public function test_a_slot_never_starts_at_or_after_closing_and_respects_the_cut_off(): void
    {
        $schedule = $this->schedule([['09:00', '22:00']]);

        $this->assertSame(['07 21:30', '07 21:45'], $this->times($schedule, 15, 0, '2026-10-07 21:30', '2026-10-07 23:59'));
        $this->assertSame(['07 21:15', '07 21:30'], $this->times($schedule, 15, 30, '2026-10-07 21:15', '2026-10-07 23:59'));
        $this->assertSame(['07 21:30'], $this->times($schedule, 30, 20, '2026-10-07 21:30', '2026-10-07 23:59'));
        // A cut-off as long as the whole period leaves exactly the opening time; a longer one leaves nothing.
        $short = $this->schedule([['12:00', '13:00']]);
        $this->assertSame(['07 12:00'], $this->times($short, 15, 60, '2026-10-07 08:00', '2026-10-07 23:59'));
        $this->assertSame([], $this->times($short, 15, 61, '2026-10-07 08:00', '2026-10-07 23:59'));
    }

    public function test_gaps_overnight_periods_and_special_dates(): void
    {
        $split = $this->schedule([['11:00', '14:00'], ['17:00', '22:00']]);
        $this->assertSame(['07 13:30', '07 17:00', '07 17:30'], $this->times($split, 30, 0, '2026-10-07 13:30', '2026-10-07 17:30'));

        $night = $this->schedule([['18:00', '02:00']]);
        $this->assertSame(['07 23:30', '08 00:00', '08 00:30', '08 01:00', '08 01:30', '08 18:00'], $this->times($night, 30, 0, '2026-10-07 23:30', '2026-10-08 18:00'));
        // The tail of yesterday's period is found when asking after midnight.
        $this->assertSame(['08 01:00', '08 01:30'], $this->times($night, 30, 0, '2026-10-08 00:45', '2026-10-08 12:00'));

        $closedToday = $this->schedule([['09:00', '22:00']], ['2026-10-07' => ['closed' => true, 'periods' => [], 'note' => null]]);
        $this->assertSame(['08 09:00'], $this->times($closedToday, 60, 0, '2026-10-07 08:00', '2026-10-08 09:00'));
        $late = $this->schedule([['09:00', '22:00']], ['2026-10-07' => ['closed' => false, 'periods' => [[Schedule::minutes('20:00'), Schedule::minutes('23:30')]], 'note' => null]]);
        $this->assertSame(['07 20:00', '07 21:00', '07 22:00', '07 23:00', '08 09:00'], $this->times($late, 60, 0, '2026-10-07 08:00', '2026-10-08 09:00'));

        // Around the clock: back-to-back days are one interval, and the walk simply goes on.
        $always = $this->schedule([['00:00', '00:00']]);
        $this->assertCount(1, $always->openIntervals($this->at('2026-10-07 10:00'), $this->at('2026-10-09 10:00')));
        $this->assertSame(['07 23:00', '08 00:00', '08 01:00'], $this->times($always, 60, 0, '2026-10-07 23:00', '2026-10-08 01:00'));
    }

    public function test_the_answer_is_bounded_and_says_when_it_was_cut(): void
    {
        $schedule = $this->schedule([['00:00', '00:00']]);
        $generator = new PickupSlotGenerator;

        $cut = $generator->candidates($schedule, 5, 0, $this->at('2026-10-07 00:00'), $this->at('2026-10-14 00:00'), 50);
        $this->assertCount(50, $cut['slots']);
        $this->assertTrue($cut['truncated']);
        $all = $generator->candidates($schedule, 60, 0, $this->at('2026-10-07 00:00'), $this->at('2026-10-07 05:00'), 50);
        $this->assertCount(6, $all['slots']);
        $this->assertFalse($all['truncated']);
        // An empty window, a window that ends before it starts, a closed restaurant.
        $this->assertSame([], $generator->candidates($schedule, 15, 0, $this->at('2026-10-07 10:00'), $this->at('2026-10-07 09:00'), 50)['slots']);
        $this->assertSame([], $generator->candidates(new Schedule(self::ZONE, []), 15, 0, $this->at('2026-10-07 10:00'), $this->at('2026-10-09 10:00'), 50)['slots']);
    }

    public function test_alignment_helpers_work_on_the_restaurant_clock(): void
    {
        // Asia/Kolkata is UTC+05:30: the grid is NOT the UTC grid.
        $instant = CarbonImmutable::parse('2026-10-07T06:52:10Z');   // 12:22:10 IST
        $this->assertSame('12:30', PickupSlotGenerator::alignUp($instant, 15, self::ZONE)->setTimezone(self::ZONE)->format('H:i'));
        $this->assertSame('12:15', PickupSlotGenerator::alignDown($instant, 15, self::ZONE)->setTimezone(self::ZONE)->format('H:i'));
        $this->assertSame('13:00', PickupSlotGenerator::alignUp($instant, 60, self::ZONE)->setTimezone(self::ZONE)->format('H:i'));
        $this->assertSame('12:00', PickupSlotGenerator::alignDown($instant, 60, self::ZONE)->setTimezone(self::ZONE)->format('H:i'));
        // Already on the grid: unchanged.
        $on = CarbonImmutable::parse('2026-10-07 12:30', self::ZONE);
        $this->assertTrue(PickupSlotGenerator::alignUp($on, 15, self::ZONE)->equalTo($on));
        $this->assertTrue(PickupSlotGenerator::alignDown($on, 15, self::ZONE)->equalTo($on));
        // A zone with a 45-minute offset (Asia/Kathmandu, +05:45).
        $this->assertSame('12:30', PickupSlotGenerator::alignUp(CarbonImmutable::parse('2026-10-07 12:16', 'Asia/Kathmandu'), 30, 'Asia/Kathmandu')->setTimezone('Asia/Kathmandu')->format('H:i'));
    }
}
