<?php

namespace App\Services\Pickup;

use App\Services\Restaurant\Schedule;
use Carbon\CarbonImmutable;

/**
 * Turns opening hours into candidate pickup slots (Module 28). Pure calculation: no database, no clock of its own.
 *
 * Alignment — slots lie on a grid counted from LOCAL MIDNIGHT of the restaurant in steps of the slot interval,
 * whatever minute the restaurant opens: with a 15-minute interval the slots are …:00, :15, :30, :45, so a
 * restaurant opening at 10:07 offers 10:15 first (never 10:07 or 10:22).
 *
 * A slot start is offered inside an open interval when it is
 *     at or after the opening time,
 *     at or after `notBefore` (the earliest time the order can be ready),
 *     at or before `notAfter`  (the scheduling horizon),
 *     before the closing time, and at or before closing time minus the cut-off.
 * So a slot never starts at or after closing, never in a gap between two opening periods, and an overnight
 * period simply continues past midnight into the next calendar day.
 *
 * Time zones — the grid is found on the restaurant's wall clock, but the walk from slot to slot is done in
 * absolute time. When clocks go forward the missing hour produces no slot; when they go back the repeated hour
 * produces two different instants (distinguishable by their UTC offset). Every interval the platform allows
 * divides 60, so the grid stays aligned across such a change; any other interval is re-aligned slot by slot.
 */
final class PickupSlotGenerator
{
    /**
     * @return array{slots: list<array{0: CarbonImmutable, 1: CarbonImmutable}>, truncated: bool} [start, end) per slot, in order
     */
    public function candidates(Schedule $schedule, int $intervalMinutes, int $cutoffMinutes, CarbonImmutable $notBefore, CarbonImmutable $notAfter, int $max): array
    {
        $interval = max(1, $intervalMinutes);
        $slots = [];
        if ($notAfter->lessThan($notBefore)) {
            return ['slots' => [], 'truncated' => false];
        }

        foreach ($schedule->openIntervals($notBefore, $notAfter->addSecond()) as [$opens, $closes]) {
            $lastStart = $closes->subMinutes(max(0, $cutoffMinutes));
            $cursor = self::alignUp($opens->greaterThan($notBefore) ? $opens : $notBefore, $interval, $schedule->timezone);

            while ($cursor->lessThanOrEqualTo($notAfter) && $cursor->lessThan($closes) && $cursor->lessThanOrEqualTo($lastStart)) {
                if (count($slots) >= $max) {
                    return ['slots' => $slots, 'truncated' => true];
                }
                $slots[] = [$cursor, $cursor->addMinutes($interval)];
                $cursor = self::alignUp($cursor->addMinutes($interval), $interval, $schedule->timezone);
            }
        }

        return ['slots' => $slots, 'truncated' => false];
    }

    /**
     * The start of the grid slot that contains $instant (the capacity bucket of an ASAP order).
     */
    public static function alignDown(CarbonImmutable $instant, int $intervalMinutes, string $timezone): CarbonImmutable
    {
        $instant = $instant->utc()->startOfMinute();
        $local = $instant->setTimezone($timezone);

        return $instant->subMinutes(($local->hour * 60 + $local->minute) % max(1, $intervalMinutes));
    }

    /**
     * The first instant at or after $instant that lies on the grid of the restaurant's local day.
     */
    public static function alignUp(CarbonImmutable $instant, int $intervalMinutes, string $timezone): CarbonImmutable
    {
        $interval = max(1, $intervalMinutes);
        // Whole minutes only, and arithmetic in UTC: adding minutes must add elapsed time, also across a clock change.
        $instant = $instant->utc();
        if ($instant->second > 0 || $instant->microsecond > 0) {
            $instant = $instant->startOfMinute()->addMinute();
        }
        for ($i = 0; $i < 3; $i++) {
            $local = $instant->setTimezone($timezone);
            $rest = ($local->hour * 60 + $local->minute) % $interval;
            if ($rest === 0) {
                break;
            }
            $instant = $instant->addMinutes($interval - $rest);
        }

        return $instant;
    }
}
