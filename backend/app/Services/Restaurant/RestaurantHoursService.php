<?php

namespace App\Services\Restaurant;

use App\Auth\Principal;
use App\Enums\OpenState;
use App\Enums\OperationalStatus;
use App\Exceptions\ApiException;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationHour;
use App\Models\RestaurantSpecialHour;
use App\Services\Audit\AuditRecorder;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening hours of a location: the server's answer to "is it open?", and the only place hours are changed.
 *
 *  - Time zone: every calculation uses the location's own IANA zone, never the server's.
 *  - Weekly hours: several periods per day; a period may run past midnight; periods must not overlap.
 *  - Special hours (one local date): replace the periods that would open on that date — closed all day, or
 *    different periods. Regular hours apply again the next day.
 *  - The weekly schedule is replaced as a whole inside one transaction, so a failure can never leave half a week.
 *
 * "Open" is a fact about the outlet. Whether FoodOnTheGo orders can be placed also depends on approval, the
 * service area and the accepting-orders switch — that is RestaurantAvailabilityService.
 */
final class RestaurantHoursService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly RestaurantCatalog $catalog) {}

    /**
     * The schedule of a location from its (eager-loaded) hours and special hours.
     */
    public function schedule(RestaurantLocation $location): Schedule
    {
        $location->loadMissing(['hours', 'specialHours.periods']);

        $weekly = [];
        foreach ($location->hours as $hour) {
            $weekly[$hour->day_of_week][] = [Schedule::minutes($hour->opens()), Schedule::minutes($hour->closes())];
        }

        $special = [];
        foreach ($location->specialHours as $day) {
            $special[$day->day()] = [
                'closed' => $day->is_closed,
                'periods' => $day->periods->map(fn ($p): array => [Schedule::minutes($p->opens()), Schedule::minutes($p->closes())])->all(),
                'note' => $day->public_note,
            ];
        }

        return new Schedule($location->timezone, $weekly, $special);
    }

    public function isOpenAt(RestaurantLocation $location, ?CarbonInterface $at = null): bool
    {
        return $this->openState($location, $at) === OpenState::Open;
    }

    /**
     * OPEN, CLOSED, or TEMPORARILY_CLOSED when the outlet has switched itself off regardless of its hours.
     */
    public function openState(RestaurantLocation $location, ?CarbonInterface $at = null): OpenState
    {
        if ($location->operational_status === OperationalStatus::TemporarilyClosed) {
            return OpenState::TemporarilyClosed;
        }

        return $this->schedule($location)->isOpenAt($at ?? now()) ? OpenState::Open : OpenState::Closed;
    }

    /**
     * End of the period that is running at $at (null when closed, or when open around the clock).
     */
    public function getCurrentPeriod(RestaurantLocation $location, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        return $this->schedule($location)->closesAt($at ?? now());
    }

    public function getNextOpenTime(RestaurantLocation $location, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        return $this->schedule($location)->opensNextAt($at ?? now());
    }

    /**
     * Opening periods of one local date ("YYYY-MM-DD") as "HH:MM" pairs, special hours applied.
     *
     * @return list<array{opens_at: string, closes_at: string}>
     */
    public function getHoursForDate(RestaurantLocation $location, string $date): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $location->timezone);

        return array_map(fn (array $p): array => ['opens_at' => Schedule::clock($p[0]), 'closes_at' => Schedule::clock($p[1])], $this->schedule($location)->periodsOpeningOn($day));
    }

    /**
     * Replaces the whole weekly schedule atomically.
     *
     * @param  list<array{day_of_week: int, opens_at: string, closes_at: string}>  $periods
     */
    public function replaceWeekly(RestaurantLocation $location, array $periods, int $version, Principal $actor): RestaurantLocation
    {
        $byDay = [];
        foreach ($periods as $index => $period) {
            $byDay[(int) $period['day_of_week']][] = [Schedule::minutes($period['opens_at']), Schedule::minutes($period['closes_at']), $index];
        }
        $this->assertWeekly($byDay);

        return DB::transaction(function () use ($location, $byDay, $version, $actor): RestaurantLocation {
            $locked = RestaurantLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->hours_version !== $version) {
                throw ApiException::conflict('stale_update', 'This record was changed by someone else. Reload it and try again.', ['current_version' => (int) $locked->hours_version]);
            }

            $before = $this->summary(RestaurantLocationHour::query()->where('location_id', $locked->getKey())->where('kind', RestaurantLocationHour::OPENING)->orderBy('day_of_week')->orderBy('sequence')->get()
                ->map(fn (RestaurantLocationHour $h): array => [$h->day_of_week, Schedule::minutes($h->opens()), Schedule::minutes($h->closes())])->all());

            RestaurantLocationHour::query()->where('location_id', $locked->getKey())->where('kind', RestaurantLocationHour::OPENING)->delete();
            $rows = [];
            $after = [];
            ksort($byDay);
            foreach ($byDay as $day => $list) {
                usort($list, fn (array $a, array $b): int => $a[0] <=> $b[0]);
                foreach ($list as $sequence => [$opens, $closes]) {
                    $rows[] = [
                        'location_id' => $locked->getKey(), 'kind' => RestaurantLocationHour::OPENING, 'day_of_week' => $day, 'sequence' => $sequence,
                        'opens_at' => Schedule::clock($opens), 'closes_at' => Schedule::clock($closes), 'created_at' => now(), 'updated_at' => now(),
                    ];
                    $after[] = [$day, $opens, $closes];
                }
            }
            if ($rows !== []) {
                RestaurantLocationHour::query()->insert($rows);
            }

            $locked->forceFill(['hours_version' => (int) $locked->hours_version + 1])->save();
            $this->audit->record('restaurant_location.hours_changed', $locked, $actor, ['weekly_hours' => ['from' => $before, 'to' => $this->summary($after)]], null, (int) $locked->market_id);
            $this->catalog->flush();

            return $locked;
        });
    }

    /**
     * @param  array{date: string, is_closed: bool, periods?: list<array{opens_at: string, closes_at: string}>, public_note?: string|null, internal_note?: string|null}  $input
     */
    public function createSpecial(RestaurantLocation $location, array $input, Principal $actor): RestaurantSpecialHour
    {
        $this->assertSpecial($location, $input, null);

        return DB::transaction(function () use ($location, $input, $actor): RestaurantSpecialHour {
            $special = (new RestaurantSpecialHour)->forceFill(['location_id' => $location->getKey(), 'date' => $input['date'], 'is_closed' => $input['is_closed']])->fill($this->notes($input));
            $special->save();
            $this->writePeriods($special, $input);

            $this->audit->record('restaurant_location.special_hours_added', $location, $actor, ['special_hours' => ['from' => null, 'to' => $this->describe($input)]], null, (int) $location->market_id);
            $this->catalog->flush();

            return $special->load('periods');
        });
    }

    /**
     * @param  array{date?: string, is_closed: bool, periods?: list<array{opens_at: string, closes_at: string}>, public_note?: string|null, internal_note?: string|null}  $input
     */
    public function updateSpecial(RestaurantLocation $location, RestaurantSpecialHour $special, array $input, Principal $actor): RestaurantSpecialHour
    {
        $input['date'] ??= $special->day();
        $this->assertSpecial($location, $input, $special);

        return DB::transaction(function () use ($location, $special, $input, $actor): RestaurantSpecialHour {
            $special->load('periods');
            $before = ['date' => $special->day(), 'is_closed' => $special->is_closed, 'periods' => $special->periods->map(fn ($p): string => $p->opens().'-'.$p->closes())->all(), 'public_note' => $special->public_note];

            $special->forceFill(['date' => $input['date'], 'is_closed' => $input['is_closed']])->fill($this->notes($input, $special))->save();
            $special->periods()->delete();
            $this->writePeriods($special, $input);

            $this->audit->record('restaurant_location.special_hours_changed', $location, $actor, ['special_hours' => ['from' => $before, 'to' => $this->describe($input + ['public_note' => $special->public_note])]], null, (int) $location->market_id);
            $this->catalog->flush();

            return $special->load('periods');
        });
    }

    public function deleteSpecial(RestaurantLocation $location, RestaurantSpecialHour $special, Principal $actor): void
    {
        DB::transaction(function () use ($location, $special, $actor): void {
            $special->load('periods');
            $before = ['date' => $special->day(), 'is_closed' => $special->is_closed, 'periods' => $special->periods->map(fn ($p): string => $p->opens().'-'.$p->closes())->all(), 'public_note' => $special->public_note];
            $special->delete();

            $this->audit->record('restaurant_location.special_hours_removed', $location, $actor, ['special_hours' => ['from' => $before, 'to' => null]], null, (int) $location->market_id);
            $this->catalog->flush();
        });
    }

    /**
     * Weekly periods must not overlap — on the same day, with the next day (an overnight period), or around
     * the end of the week. Touching periods (one ends when the next starts) are fine.
     *
     * @param  array<int, list<array{0: int, 1: int, 2: int}>>  $byDay  day => [opens, closes, index in the request]
     */
    private function assertWeekly(array $byDay): void
    {
        $limit = (int) config('restaurant.limits.periods_per_day');
        $intervals = [];

        foreach ($byDay as $day => $list) {
            if (count($list) > $limit) {
                throw ValidationException::withMessages(["periods.{$list[$limit][2]}" => ["A day can have at most {$limit} opening periods."]]);
            }
            foreach ($list as [$opens, $closes, $index]) {
                $start = $day * 1440 + $opens;
                $intervals[] = [$start, $start + $this->duration($opens, $closes), $index];
            }
        }

        $this->assertNoOverlap($intervals, 7 * 1440);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertSpecial(RestaurantLocation $location, array $input, ?RestaurantSpecialHour $existing): void
    {
        $today = CarbonImmutable::now($location->timezone)->format('Y-m-d');
        $last = CarbonImmutable::now($location->timezone)->addDays((int) config('restaurant.limits.special_hours_days_ahead'))->format('Y-m-d');

        if ($input['date'] < $today && ($existing === null || $existing->day() !== $input['date'])) {
            throw ValidationException::withMessages(['date' => ['Special hours cannot be added for a date in the past.']]);
        }
        if ($input['date'] > $last) {
            throw ValidationException::withMessages(['date' => ['Special hours can be set at most one year ahead.']]);
        }

        $duplicate = RestaurantSpecialHour::query()->where('location_id', $location->getKey())->where('date', $input['date'])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['date' => ['Special hours already exist for this date.']]);
        }

        $periods = $input['periods'] ?? [];
        if ($input['is_closed'] && $periods !== []) {
            throw ValidationException::withMessages(['periods' => ['A closed day cannot have opening periods.']]);
        }
        if (! $input['is_closed'] && $periods === []) {
            throw ValidationException::withMessages(['periods' => ['An open day needs at least one opening period.']]);
        }
        if (count($periods) > (int) config('restaurant.limits.periods_per_day')) {
            throw ValidationException::withMessages(['periods' => ['A day can have at most '.(int) config('restaurant.limits.periods_per_day').' opening periods.']]);
        }

        $intervals = [];
        foreach ($periods as $index => $period) {
            $opens = Schedule::minutes($period['opens_at']);
            $intervals[] = [$opens, $opens + $this->duration($opens, Schedule::minutes($period['closes_at'])), $index];
        }
        $this->assertNoOverlap($intervals, null);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $intervals  [start, end, index], minutes
     * @param  int|null  $cycle  length of the repeating cycle (a week), or null for a single day
     */
    private function assertNoOverlap(array $intervals, ?int $cycle): void
    {
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($intervals as $i => [$start, $end, $index]) {
            $next = $intervals[$i + 1] ?? null;
            if ($next !== null && $next[0] < $end) {
                throw ValidationException::withMessages(["periods.{$next[2]}" => ['Opening periods must not overlap.']]);
            }
        }

        // The last period of the week may run into Sunday morning: it must end before the first period starts.
        if ($cycle !== null && $intervals !== []) {
            $last = end($intervals);
            $first = $intervals[0];
            if (count($intervals) > 1 && $first[0] < $last[1] - $cycle) {
                throw ValidationException::withMessages(["periods.{$first[2]}" => ['Opening periods must not overlap.']]);
            }
            if (count($intervals) === 1 && $cycle < $last[1] - $last[0]) {
                throw ValidationException::withMessages(["periods.{$first[2]}" => ['Opening periods must not overlap.']]);
            }
        }
    }

    /**
     * Length of a period in minutes; `closes` at or before `opens` runs into the next day (equal = 24 hours).
     */
    private function duration(int $opens, int $closes): int
    {
        return $closes > $opens ? $closes - $opens : $closes - $opens + 1440;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function writePeriods(RestaurantSpecialHour $special, array $input): void
    {
        $periods = $input['is_closed'] ? [] : ($input['periods'] ?? []);
        usort($periods, fn (array $a, array $b): int => strcmp($a['opens_at'], $b['opens_at']));

        foreach ($periods as $sequence => $period) {
            $special->periods()->create(['opens_at' => $period['opens_at'], 'closes_at' => $period['closes_at'], 'sequence' => $sequence]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function notes(array $input, ?RestaurantSpecialHour $existing = null): array
    {
        $notes = [];
        foreach (['public_note', 'internal_note'] as $field) {
            if (array_key_exists($field, $input)) {
                $notes[$field] = PlainText::clean($input[$field] === null ? null : (string) $input[$field], $field);
            } elseif ($existing === null) {
                $notes[$field] = null;
            }
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function describe(array $input): array
    {
        return [
            'date' => $input['date'], 'is_closed' => (bool) $input['is_closed'],
            'periods' => array_map(fn (array $p): string => $p['opens_at'].'-'.$p['closes_at'], $input['is_closed'] ? [] : ($input['periods'] ?? [])),
            'public_note' => $input['public_note'] ?? null,
        ];
    }

    /**
     * Compact, readable form of a weekly schedule for the audit trail: "1 09:00-17:00".
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $periods  [day, opens, closes]
     * @return list<string>
     */
    private function summary(array $periods): array
    {
        return array_map(fn (array $p): string => $p[0].' '.Schedule::clock($p[1]).'-'.Schedule::clock($p[2]), $periods);
    }
}
