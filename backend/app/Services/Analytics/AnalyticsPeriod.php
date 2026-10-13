<?php

namespace App\Services\Analytics;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * A reporting period (Module 38): whole LOCAL days of an explicit time zone, both ends included, and the instants
 * they begin and end at in UTC. The zone is never assumed — it is the market's (or the restaurant's) own, so a
 * period in a zone with daylight saving time has 23- and 25-hour days where the calendar says so.
 */
final readonly class AnalyticsPeriod
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'custom'];

    private function __construct(
        public string $preset,
        public CarbonImmutable $from,   // start of the first local day
        public CarbonImmutable $to,     // start of the last local day
        public string $timezone,
    ) {}

    /**
     * @param  array{preset?: ?string, from?: ?string, to?: ?string}  $input  already validated as strings
     */
    public static function fromInput(array $input, string $timezone, ?int $maxDays = null): self
    {
        $timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $preset = (string) ($input['preset'] ?? (isset($input['from']) || isset($input['to']) ? 'custom' : config('analytics.periods.default')));
        if (! in_array($preset, self::PRESETS, true)) {
            throw self::invalid('preset', 'This period is not supported.');
        }
        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'previous_month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            default => [self::day($input['from'] ?? null, $timezone, 'from'), self::day($input['to'] ?? null, $timezone, 'to')],
        };
        $max = $maxDays ?? (int) config('analytics.periods.max_range_days');
        if ($from->greaterThan($to)) {
            throw self::invalid('from', 'The period must run from an earlier day to a later one.');
        }
        if (self::daysBetween($from, $to) > $max) {
            throw new ApiException(422, 'range_too_long', 'A period covers at most '.$max.' days.', ['fields' => ['from' => ['At most '.$max.' days before "to".']], 'max_days' => $max]);
        }

        return new self($preset, $from, $to, $timezone);
    }

    public static function of(string $from, string $to, string $timezone): self
    {
        return new self('custom', CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone), CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone), $timezone);
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** The first instant of the period. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    /** The first instant AFTER the period (exclusive end). */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay()->utc();
    }

    public function days(): int
    {
        return self::daysBetween($this->from, $this->to);
    }

    /**
     * The equivalent previous range: the same number of days immediately before this one.
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self('custom', $this->from->subDays($days), $this->from->subDay(), $this->timezone);
    }

    /**
     * How a chart of this period is cut: by hour, day, week (starting Monday) or month.
     */
    public function bucket(): string
    {
        $days = $this->days();

        return match (true) {
            $days <= (int) config('analytics.periods.hourly_up_to_days') => 'hour',
            $days <= (int) config('analytics.periods.daily_up_to_days') => 'day',
            $days <= (int) config('analytics.periods.weekly_up_to_days') => 'week',
            default => 'month',
        };
    }

    /**
     * The key of the bucket a local day (or, for hours, a local "Y-m-d H") falls in.
     */
    public function bucketOf(string $localDay, ?string $bucket = null): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', substr($localDay, 0, 10), 'UTC');

        return match ($bucket ?? $this->bucket()) {
            'week' => $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'month' => $day->startOfMonth()->toDateString(),
            default => $day->toDateString(),
        };
    }

    /**
     * Every bucket of the period, in order — so that a chart has an explicit zero where nothing happened.
     *
     * @return list<string>
     */
    public function buckets(?string $bucket = null): array
    {
        $bucket ??= $this->bucket();
        $keys = [];
        if ($bucket === 'hour') {
            return array_values($this->hourBuckets());
        }
        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $keys[$this->bucketOf($day->toDateString(), $bucket)] = true;
        }

        return array_keys($keys);
    }

    /**
     * The hours of the period as REAL hours: the instant each begins (UTC) => its local name. A day on which the
     * clocks go back has 25 of them — the hour that happens twice is named the second time with the zone's
     * abbreviation ("2026-11-01 01:00 (EST)"); a day on which they go forward has 23.
     *
     * @return array<string, string>
     */
    public function hourBuckets(): array
    {
        $hours = [];
        $seen = [];
        $end = $this->endUtc()->getTimestamp();
        for ($at = $this->startUtc()->getTimestamp(); $at < $end; $at += 3600) {
            $local = CarbonImmutable::createFromTimestamp($at, $this->timezone);
            $label = $local->format('Y-m-d H:00');
            if (isset($seen[$label])) {
                $label .= ' ('.$local->format('T').')';
            }
            $seen[$label] = true;
            $hours[gmdate('Y-m-d\TH:i:s\Z', $at)] = $label;
        }

        return $hours;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $previous = $this->previous();

        return [
            'preset' => $this->preset, 'from' => $this->fromDate(), 'to' => $this->toDate(), 'days' => $this->days(), 'timezone' => $this->timezone, 'bucket' => $this->bucket(),
            'starts_at' => $this->startUtc()->toIso8601ZuluString(), 'ends_before' => $this->endUtc()->toIso8601ZuluString(),
            'previous' => ['from' => $previous->fromDate(), 'to' => $previous->toDate()],
        ];
    }

    /** Calendar days from … to, both included (never a count of hours: a day may have 23 or 25). */
    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round((strtotime($to->toDateString().' UTC') - strtotime($from->toDateString().' UTC')) / 86400) + 1;
    }

    private static function day(mixed $value, string $timezone, string $field): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw self::invalid($field, 'A custom period needs "from" and "to" as days (YYYY-MM-DD).');
        }
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (\Throwable) {
            $day = null;
        }
        if (! $day instanceof CarbonImmutable || $day->toDateString() !== $value) {
            throw self::invalid($field, 'This is not a day of the calendar.');
        }

        return $day;
    }

    private static function invalid(string $field, string $message): ApiException
    {
        return new ApiException(422, 'validation_failed', 'The period is not valid.', ['fields' => [$field => [$message]]]);
    }
}
