<?php

namespace App\Services\Restaurant;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The opening schedule of one location as plain data: weekly periods plus the special dates that replace
 * them. Everything is evaluated in the time zone of the location — never in the server's or the caller's.
 *
 * A period is [opens, closes] in minutes after local midnight. `closes` at or before `opens` runs past
 * midnight (equal = 24 hours). A period belongs to the local date on which it opens, so special hours for a
 * date replace the periods opening on that date; a period that opened the evening before still runs to its end.
 *
 * Instants are built from wall-clock times (date + "HH:MM" in the zone), so a daylight-saving change keeps
 * "opens at 09:00" at 09:00.
 */
final readonly class Schedule
{
    private const LOOKAHEAD_DAYS = 14;

    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $weekly  day of week (0 = Sunday) => periods
     * @param  array<string, array{closed: bool, periods: list<array{0: int, 1: int}>, note: string|null}>  $special  "YYYY-MM-DD" => exception
     */
    public function __construct(public string $timezone, public array $weekly, public array $special = []) {}

    /**
     * Periods that open on the given local date: the special hours of that date, otherwise the weekly ones.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function periodsOpeningOn(CarbonInterface $localDate): array
    {
        $date = $localDate->format('Y-m-d');

        if (isset($this->special[$date])) {
            return $this->special[$date]['closed'] ? [] : $this->special[$date]['periods'];
        }

        return $this->weekly[$localDate->dayOfWeek] ?? [];
    }

    public function isOpenAt(CarbonInterface $at): bool
    {
        return $this->periodContaining($at) !== null;
    }

    /**
     * The instant the restaurant closes, when it is open at $at. Back-to-back periods (a 24-hour day followed by
     * the next one) are joined; null means "not open" — or open without a foreseeable end (around the clock).
     */
    public function closesAt(CarbonInterface $at): ?CarbonImmutable
    {
        $period = $this->periodContaining($at);
        if ($period === null) {
            return null;
        }

        $end = $period[1];
        for ($i = 0; $i < self::LOOKAHEAD_DAYS * 4; $i++) {
            $next = $this->periodContaining($end);
            if ($next === null) {
                return $end;
            }
            $end = $next[1];
        }

        return null;
    }

    /**
     * The next instant after $at at which a period opens, within the coming two weeks.
     */
    public function opensNextAt(CarbonInterface $at): ?CarbonImmutable
    {
        $instant = CarbonImmutable::instance($at);
        $day = $instant->setTimezone($this->timezone)->startOfDay();

        for ($i = 0; $i <= self::LOOKAHEAD_DAYS; $i++) {
            $best = null;
            foreach ($this->periodsOpeningOn($day) as $period) {
                [$start] = $this->instants($day, $period);
                if ($start->greaterThan($instant) && ($best === null || $start->lessThan($best))) {
                    $best = $start;
                }
            }
            if ($best !== null) {
                return $best;
            }
            $day = $day->addDay();
        }

        return null;
    }

    /**
     * Whether a special date applies on the local date of $at.
     */
    public function specialOn(CarbonInterface $at): ?array
    {
        return $this->special[CarbonImmutable::instance($at)->setTimezone($this->timezone)->format('Y-m-d')] ?? null;
    }

    /**
     * The [start, end) instants of the period that contains $at, looking at the periods opening on the local
     * date and on the day before (overnight tail).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function periodContaining(CarbonInterface $at): ?array
    {
        $instant = CarbonImmutable::instance($at);
        $today = $instant->setTimezone($this->timezone)->startOfDay();

        foreach ([$today, $today->subDay()] as $day) {
            foreach ($this->periodsOpeningOn($day) as $period) {
                [$start, $end] = $this->instants($day, $period);
                if ($instant->greaterThanOrEqualTo($start) && $instant->lessThan($end)) {
                    return [$start, $end];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: int}  $period
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function instants(CarbonImmutable $day, array $period): array
    {
        [$opens, $closes] = $period;
        $start = $day->setTime(intdiv($opens, 60), $opens % 60);
        $endDay = $closes > $opens ? $day : $day->addDay();

        return [$start, $endDay->setTime(intdiv($closes, 60), $closes % 60)];
    }

    /**
     * "HH:MM" → minutes after midnight.
     */
    public static function minutes(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    public static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
