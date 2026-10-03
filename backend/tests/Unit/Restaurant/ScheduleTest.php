<?php

namespace Tests\Unit\Restaurant;

use App\Services\Restaurant\Schedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Opening-hours rules as plain logic. 2026-10-05 is a Monday (day 1); times are written in the zone of the
 * schedule and converted to instants, the way a request arrives.
 */
class ScheduleTest extends TestCase
{
    private const KOLKATA = 'Asia/Kolkata';

    private function at(string $local, string $zone = self::KOLKATA): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $zone)->utc();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $periods
     * @return array<int, list<array{0: int, 1: int}>>
     */
    private function everyDay(array $periods): array
    {
        return array_fill(0, 7, array_map(fn (array $p): array => [Schedule::minutes($p[0]), Schedule::minutes($p[1])], $periods));
    }

    public function test_normal_hours_open_inside_the_period_and_closed_outside(): void
    {
        $schedule = new Schedule(self::KOLKATA, $this->everyDay([['09:00', '17:00']]));

        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-05 08:59')));
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 09:00')), 'the opening minute is open');
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 16:59')));
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-05 17:00')), 'the closing minute is closed');

        $this->assertEquals($this->at('2026-10-05 17:00'), $schedule->closesAt($this->at('2026-10-05 12:00')));
        $this->assertNull($schedule->closesAt($this->at('2026-10-05 18:00')), 'not open, so nothing closes');
        $this->assertEquals($this->at('2026-10-06 09:00'), $schedule->opensNextAt($this->at('2026-10-05 18:00')));
        $this->assertEquals($this->at('2026-10-05 09:00'), $schedule->opensNextAt($this->at('2026-10-05 07:00')));
    }

    public function test_several_periods_a_day_are_closed_in_between(): void
    {
        $schedule = new Schedule(self::KOLKATA, $this->everyDay([['10:00', '15:00'], ['18:00', '22:30']]));

        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 12:00')));
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-05 16:00')), 'afternoon break');
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 19:00')));

        $this->assertEquals($this->at('2026-10-05 15:00'), $schedule->closesAt($this->at('2026-10-05 12:00')));
        $this->assertEquals($this->at('2026-10-05 18:00'), $schedule->opensNextAt($this->at('2026-10-05 16:00')));
        $this->assertEquals($this->at('2026-10-05 22:30'), $schedule->closesAt($this->at('2026-10-05 19:00')));
    }

    public function test_an_overnight_period_runs_past_midnight_and_belongs_to_the_day_it_opens(): void
    {
        // Friday (5) and Saturday (6) only: 20:00 – 02:00.
        $schedule = new Schedule(self::KOLKATA, [5 => [[1200, 120]], 6 => [[1200, 120]]]);

        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-09 23:30')), 'Friday night');
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-10 01:59')), 'Saturday 01:59 is still Friday\'s period');
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-10 02:00')));
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-11 01:00')), 'Sunday 01:00 is the tail of Saturday');
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-12 01:00')), 'Monday 01:00: Sunday has no period');

        $this->assertEquals($this->at('2026-10-10 02:00'), $schedule->closesAt($this->at('2026-10-09 23:30')));
        $this->assertEquals($this->at('2026-10-10 20:00'), $schedule->opensNextAt($this->at('2026-10-10 03:00')));
    }

    public function test_a_closed_day_has_no_period_and_the_next_opening_skips_it(): void
    {
        $weekly = $this->everyDay([['10:30', '22:00']]);
        unset($weekly[1]);   // closed on Mondays
        $schedule = new Schedule(self::KOLKATA, $weekly);

        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-05 13:00')));
        $this->assertSame([], $schedule->periodsOpeningOn(CarbonImmutable::parse('2026-10-05', self::KOLKATA)));
        $this->assertEquals($this->at('2026-10-06 10:30'), $schedule->opensNextAt($this->at('2026-10-04 22:30')), 'Sunday night → Tuesday morning');
    }

    public function test_equal_open_and_close_means_twenty_four_hours_and_never_closes(): void
    {
        $schedule = new Schedule(self::KOLKATA, $this->everyDay([['00:00', '00:00']]));

        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 03:17')));
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 23:59')));
        $this->assertNull($schedule->closesAt($this->at('2026-10-05 12:00')), 'open around the clock: no closing time to announce');
    }

    public function test_a_special_closure_replaces_the_regular_hours_of_that_date_only(): void
    {
        $schedule = new Schedule(self::KOLKATA, $this->everyDay([['09:00', '17:00']]), ['2026-10-06' => ['closed' => true, 'periods' => [], 'note' => 'Holiday']]);

        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 12:00')));
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-06 12:00')), 'closed on the special date');
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-07 12:00')), 'regular hours are back the next day');
        $this->assertEquals($this->at('2026-10-07 09:00'), $schedule->opensNextAt($this->at('2026-10-05 18:00')), 'the closed date is skipped');
        $this->assertSame('Holiday', $schedule->specialOn($this->at('2026-10-06 12:00'))['note']);
        $this->assertNull($schedule->specialOn($this->at('2026-10-07 12:00')));
    }

    public function test_special_opening_hours_replace_the_regular_ones(): void
    {
        $schedule = new Schedule(self::KOLKATA, $this->everyDay([['09:00', '17:00']]), ['2026-10-06' => ['closed' => false, 'periods' => [[660, 960]], 'note' => 'Festival hours']]);

        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-06 09:30')), 'regular 09:00 does not apply');
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-06 11:00')));
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-06 16:30')), 'special hours end at 16:00');
        $this->assertEquals($this->at('2026-10-06 16:00'), $schedule->closesAt($this->at('2026-10-06 12:00')));
    }

    public function test_a_special_date_can_open_a_normally_closed_day(): void
    {
        $schedule = new Schedule(self::KOLKATA, [2 => [[540, 1020]]], ['2026-10-05' => ['closed' => false, 'periods' => [[600, 840]], 'note' => null]]);

        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-05 11:00')), 'Monday is normally closed');
    }

    public function test_the_overnight_tail_of_the_day_before_survives_a_special_closure(): void
    {
        // Every day 18:00 – 02:00; Tuesday the 6th is closed. Monday's guests may stay until 02:00 on Tuesday.
        $schedule = new Schedule(self::KOLKATA, $this->everyDay([['18:00', '02:00']]), ['2026-10-06' => ['closed' => true, 'periods' => [], 'note' => null]]);

        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-06 01:00')), 'tail of Monday');
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-06 19:00')), 'Tuesday itself is closed');
        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-07 01:00')), 'and so it has no tail on Wednesday');
        $this->assertTrue($schedule->isOpenAt($this->at('2026-10-07 19:00')));
    }

    public function test_the_day_is_the_restaurants_local_day_not_the_servers(): void
    {
        // Monday only, 09:00 – 17:00 in India. 2026-10-05 04:00 UTC is Monday 09:30 there.
        $schedule = new Schedule(self::KOLKATA, [1 => [[540, 1020]]]);

        $this->assertTrue($schedule->isOpenAt(CarbonImmutable::parse('2026-10-05 04:00', 'UTC')));
        // 2026-10-04 20:00 UTC is still Sunday in UTC but Monday 01:30 in India — and closed at that hour.
        $this->assertFalse($schedule->isOpenAt(CarbonImmutable::parse('2026-10-04 20:00', 'UTC')));
        // 2026-10-05 23:00 UTC is still Monday in UTC but Tuesday 04:30 in India.
        $this->assertFalse($schedule->isOpenAt(CarbonImmutable::parse('2026-10-05 23:00', 'UTC')));

        // The same instant asked from another zone gives the same answer.
        $this->assertTrue($schedule->isOpenAt(CarbonImmutable::parse('2026-10-04 21:00', 'America/Los_Angeles')));
    }

    public function test_the_same_wall_clock_hours_mean_different_instants_in_different_zones(): void
    {
        $weekly = $this->everyDay([['09:00', '17:00']]);
        $instant = CarbonImmutable::parse('2026-10-05 05:00', 'UTC');   // 10:30 in Kolkata, 06:00 in London, 01:00 in New York

        $this->assertTrue((new Schedule('Asia/Kolkata', $weekly))->isOpenAt($instant));
        $this->assertFalse((new Schedule('Europe/London', $weekly))->isOpenAt($instant));
        $this->assertFalse((new Schedule('America/New_York', $weekly))->isOpenAt($instant));
    }

    public function test_opening_at_nine_stays_at_nine_across_a_daylight_saving_change(): void
    {
        // New York leaves daylight saving on Sunday 2026-11-01: 09:00 is 13:00 UTC on Saturday and 14:00 UTC on Sunday.
        $schedule = new Schedule('America/New_York', $this->everyDay([['09:00', '17:00']]));

        $this->assertTrue($schedule->isOpenAt(CarbonImmutable::parse('2026-10-31 13:00', 'UTC')));
        $this->assertFalse($schedule->isOpenAt(CarbonImmutable::parse('2026-11-01 13:30', 'UTC')), '08:30 local after the change');
        $this->assertTrue($schedule->isOpenAt(CarbonImmutable::parse('2026-11-01 14:00', 'UTC')));
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 14:00', 'UTC'), $schedule->opensNextAt(CarbonImmutable::parse('2026-10-31 23:00', 'UTC')));
    }

    public function test_a_schedule_without_any_period_never_opens(): void
    {
        $schedule = new Schedule(self::KOLKATA, []);

        $this->assertFalse($schedule->isOpenAt($this->at('2026-10-05 12:00')));
        $this->assertNull($schedule->opensNextAt($this->at('2026-10-05 12:00')));
    }

    public function test_clock_helpers(): void
    {
        $this->assertSame(570, Schedule::minutes('09:30'));
        $this->assertSame(570, Schedule::minutes('09:30:00'));
        $this->assertSame('09:30', Schedule::clock(570));
        $this->assertSame('00:00', Schedule::clock(0));
    }
}
