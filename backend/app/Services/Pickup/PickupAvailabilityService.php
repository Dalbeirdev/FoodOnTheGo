<?php

namespace App\Services\Pickup;

use App\Enums\CartStatus;
use App\Enums\PickupCapacityState;
use App\Enums\PickupIssueCode as Code;
use App\Enums\PickupMethod;
use App\Enums\PickupMode;
use App\Enums\RestaurantAvailabilityReason as Reason;
use App\Models\RestaurantLocation;
use App\Services\Cart\CartInspection;
use App\Services\Restaurant\PickupRules;
use App\Services\Restaurant\RestaurantAvailability;
use App\Services\Restaurant\RestaurantAvailabilityService;
use App\Services\Restaurant\RestaurantHoursService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single authority on WHEN an order can be collected (Module 28): ASAP, the scheduled slots, the earliest
 * pickup, the recommendation for a traveller, and the reason when nothing is possible.
 *
 * Everything is decided here, on the server clock and in the restaurant's own time zone — never from a time, a
 * restaurant, a capacity or a "valid" flag sent by a client, and never from the device clock.
 *
 *   restaurant may be ordered from?      approval chain, market / area, temporarily closed, pickup enabled,
 *                                        accepting orders (a pause that has run out no longer counts)
 *   cart can go on?                      a cart with a blocking issue or an unreviewed price change stops here
 *   preparation estimate                 PreparationTimeService
 *   kitchen ready                        now + max(estimate + buffer, minimum lead)        — rounded up to the minute
 *   ASAP                                 the kitchen-ready time, if the restaurant is open now and that time is
 *                                        still before closing minus the cut-off; a full slot moves it to the next one
 *   scheduled slots                      PickupSlotGenerator over the opening hours (special hours, several periods a
 *                                        day, overnight) from kitchen-ready to the scheduling horizon; a full slot is
 *                                        listed as unavailable
 *   recommendation                       the first valid option at or after max(kitchen ready, traveller's arrival)
 *
 * ASAP means "the earliest valid pickup time", not "immediately". A closed restaurant has no ASAP but may still
 * offer scheduled times. The answer is a snapshot (PickupAvailability): choosing an option validates it again.
 *
 * Capacity is read through PickupCapacityRepository in ONE aggregated query per answer; opening hours, settings
 * and the cart's lines are the relations the caller has already loaded. Nothing is cached: an answer depends on
 * the minute, on remaining capacity and on the accepting-orders switch.
 */
final class PickupAvailabilityService
{
    public function __construct(
        private readonly RestaurantAvailabilityService $restaurants,
        private readonly RestaurantHoursService $hours,
        private readonly PreparationTimeService $preparation,
        private readonly PickupSlotGenerator $generator,
        private readonly PickupCapacityRepository $capacity,
    ) {}

    /**
     * Pickup options for a cart (already inspected by CartValidationService, so nothing is loaded twice).
     */
    public function forCart(CartInspection $inspection, ?PickupMethod $method = null, ?CarbonInterface $at = null): PickupAvailability
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cart = $inspection->cart;
        $location = $cart->location;
        $state = $inspection->restaurant ?? $this->restaurants->evaluate($location, $at);

        $issues = [];
        if ($inspection->status === CartStatus::Expired) {
            $issues[] = self::issue(Code::CartExpired, 'This cart has expired. Start a new cart to continue.');
        }
        $restaurantIssue = $this->restaurantIssue($state, $location);
        if ($restaurantIssue !== null) {
            $issues[] = $restaurantIssue;
        } elseif ($issues === [] && ! $inspection->canProceed()) {
            // The restaurant is fine, so what stops the cart is in the cart itself: an item, an option, a price to review.
            $issues[] = self::issue(Code::CartInvalid, 'Your cart needs your attention before a pickup time can be chosen.', ['cart_validation' => $inspection->validation->value]);
        }

        $settings = $location->pickupSettings;

        return $this->build(
            location: $location,
            state: $state,
            preparation: $settings === null ? null : $this->preparation->forCart($cart, $settings),
            method: $method,
            at: $at,
            issues: $issues,
            cartVersion: (int) $cart->version,
            journey: $this->journeyContext($inspection, $at),
        );
    }

    /**
     * What a customer whose order needs no more than the default preparation time would be offered right now —
     * the same engine without a cart, for the restaurant's own settings page. Never a second slot generator.
     */
    public function preview(RestaurantLocation $location, ?CarbonInterface $at = null): PickupAvailability
    {
        $at = CarbonImmutable::instance($at ?? now());
        $state = $this->restaurants->evaluate($location, $at);
        $issue = $this->restaurantIssue($state, $location);
        $settings = $location->pickupSettings;

        return $this->build(
            location: $location,
            state: $state,
            preparation: $settings === null ? null : $this->preparation->forDefault($settings),
            method: null,
            at: $at,
            issues: $issue === null ? [] : [$issue],
            cartVersion: null,
            journey: null,
        );
    }

    /**
     * Warnings for the restaurant about a configuration that offers customers little or nothing — found with the
     * engine's own answer and the real opening hours, so the settings page never guesses.
     *
     * @return list<array{code: string, message: string}>
     */
    public function configurationWarnings(RestaurantLocation $location, PickupAvailability $preview, ?CarbonInterface $at = null): array
    {
        $settings = $location->pickupSettings;
        if ($settings === null || ! $settings->pickup_enabled || $preview->preparation === null) {
            return [];
        }
        $at = CarbonImmutable::instance($at ?? now());
        $warnings = [];
        $week = $this->hours->schedule($location)->openIntervals($at, $at->addDays(7));
        $ready = $preview->preparation->readyInMinutes();
        $cutoff = (int) $settings->order_cutoff_minutes;

        if ($week === []) {
            $warnings[] = ['code' => 'NO_OPENING_HOURS', 'message' => 'There are no opening hours in the next 7 days, so customers cannot be offered a pickup time.'];
        } else {
            $longest = max(array_map(fn (array $i): int => intdiv($i[1]->getTimestamp() - $i[0]->getTimestamp(), 60), $week));
            if ($cutoff > $longest) {
                $warnings[] = ['code' => 'CUTOFF_LONGER_THAN_OPENING', 'message' => "The cut-off before closing ({$cutoff} min) is longer than your longest opening period ({$longest} min): no pickup time can be offered."];
            } elseif ($settings->asap_enabled && $ready + $cutoff >= $longest) {
                $warnings[] = ['code' => 'ASAP_NEVER_POSSIBLE', 'message' => "Preparation, buffer and lead time ({$ready} min) plus the cut-off ({$cutoff} min) do not fit into your longest opening period ({$longest} min): ASAP pickup can never be offered."];
            }
        }
        if (! $preview->isBlocked() || ($preview->blockingIssue()['code'] ?? null) === Code::NoPickupSlots->value) {
            if ($preview->slots !== [] && $preview->availableSlotCount() === 0) {
                $warnings[] = ['code' => 'ALL_SLOTS_FULL', 'message' => 'Every pickup time in the scheduling window is at capacity.'];
            } elseif (! $preview->asap['available'] && $preview->availableSlotCount() === 0) {
                $warnings[] = ['code' => 'NO_PICKUP_TIMES', 'message' => 'With these settings and opening hours customers are offered no pickup time right now.'];
            }
        }

        return $warnings;
    }

    /**
     * @param  list<array{code: string, severity: string, message: string, details: array<string, mixed>}>  $issues
     * @param  array{status: string, arrival_at: CarbonImmutable|null, requires_refresh: bool, source: string|null}|null  $journey
     */
    private function build(RestaurantLocation $location, RestaurantAvailability $state, ?PreparationEstimate $preparation, ?PickupMethod $method, CarbonImmutable $at, array $issues, ?int $cartVersion, ?array $journey): PickupAvailability
    {
        $settings = $location->pickupSettings;
        $timezone = (string) $location->timezone;
        $methods = PickupRules::offeredMethods($location);
        $modes = PickupRules::offeredModes($location);

        if ($method !== null && array_filter($methods, fn ($m): bool => $m->method === $method) === []) {
            $issues[] = self::issue(Code::PickupMethodUnavailable, 'This pickup method is not available at this restaurant.', ['method' => $method->value]);
        }
        $chosen = $method ?? ($methods[0]->method ?? null);

        $asap = ['enabled' => $modes['asap'], 'available' => false, 'pickup_at' => null, 'ready_in_minutes' => null, 'reason' => $modes['asap'] ? null : Code::AsapDisabled, 'recommended' => false];
        $scheduled = ['enabled' => $modes['scheduled'], 'interval_minutes' => $settings?->slot_interval_minutes, 'horizon_minutes' => $settings?->schedule_horizon_minutes, 'horizon_ends_at' => null, 'truncated' => false];
        $revalidateAfter = $at->addSeconds(max(5, (int) config('pickup.revalidate_after_seconds')));
        $blocked = array_filter($issues, fn (array $i): bool => $i['severity'] === PickupAvailability::BLOCKING) !== [];

        if ($blocked || $settings === null || $preparation === null) {
            return new PickupAvailability(
                location: $location, timezone: $timezone, generatedAt: $at, cartVersion: $cartVersion, settingsVersion: (int) ($settings?->version ?? 0),
                pickupEnabled: (bool) ($settings?->pickup_enabled ?? false), methods: $methods, method: $chosen, preparation: $preparation, kitchenReadyAt: null,
                asap: $asap, scheduled: $scheduled, slots: [], recommended: null, journey: $journey,
                issues: [...$issues, ...$this->journeyIssues($journey)], revalidateAfter: $revalidateAfter,
            );
        }

        $interval = max(1, (int) $settings->slot_interval_minutes);
        $cutoff = max(0, (int) $settings->order_cutoff_minutes);
        $limit = $settings->capacity_per_slot === null ? null : (int) $settings->capacity_per_slot;
        $schedule = $this->hours->schedule($location);

        // Lead time is a floor, not an addition: the order can be handed over after max(preparation + buffer, lead).
        $kitchenReady = self::ceilMinute($at->addMinutes($preparation->readyInMinutes()));
        $horizonEnd = $modes['scheduled'] ? $at->addMinutes(max(0, (int) $settings->schedule_horizon_minutes)) : $kitchenReady;
        $closesAt = $state->isOpen() ? $schedule->closesAt($at) : null;

        // Capacity in use for everything this answer can mention — one query.
        $usage = [];
        if ($limit !== null) {
            $until = $horizonEnd;
            foreach ([$closesAt, $kitchenReady] as $bound) {
                if ($bound !== null && $bound->greaterThan($until)) {
                    $until = $bound;
                }
            }
            $usage = $this->capacity->usage($location, PickupSlotGenerator::alignDown($at, $interval, $timezone), $until->addMinutes($interval), $at);
        }

        /* ------------------------------------------------------------------ ASAP */
        if ($modes['asap']) {
            if (! $state->isOpen()) {
                $asap['reason'] = Code::RestaurantClosed;
            } else {
                $lastHandOver = $closesAt?->subMinutes($cutoff);
                $fits = fn (CarbonImmutable $t): bool => $closesAt === null || ($t->lessThan($closesAt) && $t->lessThanOrEqualTo($lastHandOver));
                $candidate = $kitchenReady;
                if (! $fits($candidate)) {
                    $asap['reason'] = Code::ClosesTooSoon;
                } else {
                    // An ASAP order uses the capacity of the slot its ready time falls in: a full slot moves it on.
                    for ($guard = 0; $limit !== null && $candidate !== null; $guard++) {
                        $bucket = PickupSlotGenerator::alignDown($candidate, $interval, $timezone);
                        if (self::used($usage, $bucket, $bucket->addMinutes($interval)) < $limit) {
                            break;
                        }
                        $next = $bucket->addMinutes($interval);
                        $candidate = $guard < 500 && $fits($next) ? $next : null;
                    }
                    if ($candidate === null) {
                        $asap['reason'] = Code::FullyBooked;
                    } else {
                        $asap['available'] = true;
                        $asap['pickup_at'] = $candidate;
                        $asap['ready_in_minutes'] = (int) ceil(($candidate->getTimestamp() - $at->getTimestamp()) / 60);
                    }
                }
            }
        }

        /* ------------------------------------------------------------------ scheduled slots */
        $slots = [];
        if ($modes['scheduled']) {
            $scheduled['horizon_ends_at'] = $horizonEnd;
            $generated = $this->generator->candidates($schedule, $interval, $cutoff, $kitchenReady, $horizonEnd, max(1, (int) config('pickup.limits.max_slots')));
            $scheduled['truncated'] = $generated['truncated'];
            foreach ($generated['slots'] as [$start, $end]) {
                $capacity = self::capacityState($limit, self::used($usage, $start, $end));
                $full = $capacity === PickupCapacityState::Full;
                $slots[] = new PickupSlot(PickupSlotKey::make($location, $start, $interval), $start, $end, ! $full, $capacity, $full ? Code::SlotFull : null);
            }
        }

        /* ------------------------------------------------------------------ recommendation */
        $recommended = $this->recommend($asap, $slots, $kitchenReady, $journey);
        if ($recommended !== null && $recommended['mode'] === PickupMode::Asap) {
            $asap['recommended'] = true;
        } elseif ($recommended !== null) {
            $slots = array_map(fn (PickupSlot $s): PickupSlot => $s->key === $recommended['slot_key'] ? $s->recommend() : $s, $slots);
        }

        /* ------------------------------------------------------------------ what the customer should know */
        if (! $state->isOpen()) {
            $issues[] = self::issue(Code::RestaurantClosed, 'The restaurant is closed right now.', ['opens_next_at' => $state->opensNextAt?->utc()->toIso8601String()], PickupAvailability::INFO);
        }
        $anySlot = array_filter($slots, fn (PickupSlot $s): bool => $s->available) !== [];
        if (! $asap['available'] && ! $anySlot) {
            $cause = match (true) {
                $slots !== [] || $asap['reason'] === Code::FullyBooked => Code::FullyBooked,
                ! $modes['scheduled'] && ! $state->isOpen() => Code::RestaurantClosed,
                ! $modes['scheduled'] && $asap['reason'] === Code::ClosesTooSoon => Code::ClosesTooSoon,
                default => Code::NoPickupSlots,
            };
            $issues[] = self::issue(Code::NoPickupSlots, match ($cause) {
                Code::FullyBooked => 'All pickup times are fully booked right now.',
                Code::RestaurantClosed => 'The restaurant is closed and does not take orders for later.',
                Code::ClosesTooSoon => 'The restaurant closes before this order could be ready.',
                default => 'There is no pickup time available in the coming days.',
            }, ['cause' => $cause->value, 'opens_next_at' => $state->opensNextAt?->utc()->toIso8601String()]);
        }

        return new PickupAvailability(
            location: $location, timezone: $timezone, generatedAt: $at, cartVersion: $cartVersion, settingsVersion: (int) $settings->version,
            pickupEnabled: true, methods: $methods, method: $chosen, preparation: $preparation, kitchenReadyAt: $kitchenReady,
            asap: $asap, scheduled: $scheduled, slots: $slots, recommended: $recommended, journey: $journey,
            issues: [...$issues, ...$this->journeyIssues($journey)], revalidateAfter: $revalidateAfter,
        );
    }

    /**
     * The option to suggest: the first valid one at or after the later of "the kitchen can have it ready" and
     * "the traveller is expected to arrive" (plus the configured arrival buffer). Without a journey that is simply
     * the earliest option. When the traveller arrives after the last possible time, the latest option is suggested —
     * the food may then be ready before they arrive, which the client is told through the difference.
     *
     * @param  array{enabled: bool, available: bool, pickup_at: CarbonImmutable|null, ready_in_minutes: int|null, reason: Code|null, recommended: bool}  $asap
     * @param  list<PickupSlot>  $slots
     * @param  array{status: string, arrival_at: CarbonImmutable|null, requires_refresh: bool, source: string|null}|null  $journey
     * @return array{mode: PickupMode, pickup_at: CarbonImmutable, slot_key: string|null, basis: string}|null
     */
    private function recommend(array $asap, array $slots, CarbonImmutable $kitchenReady, ?array $journey): ?array
    {
        $arrival = $journey['arrival_at'] ?? null;
        $target = $kitchenReady;
        $basis = 'KITCHEN';
        if ($arrival !== null) {
            $wanted = $arrival->addMinutes(max(0, (int) config('pickup.arrival_buffer_minutes')));
            if ($wanted->greaterThan($target)) {
                $target = $wanted;
                $basis = 'ARRIVAL';
            }
        }

        /** @var list<array{mode: PickupMode, pickup_at: CarbonImmutable, slot_key: string|null}> $options */
        $options = [];
        if ($asap['available'] && $asap['pickup_at'] !== null) {
            $options[] = ['mode' => PickupMode::Asap, 'pickup_at' => $asap['pickup_at'], 'slot_key' => null];
        }
        foreach ($slots as $slot) {
            if ($slot->available) {
                $options[] = ['mode' => PickupMode::Scheduled, 'pickup_at' => $slot->startsAt, 'slot_key' => $slot->key];
            }
        }
        if ($options === []) {
            return null;
        }
        // ASAP first among equal times: it is the same pickup with less to decide.
        usort($options, fn (array $a, array $b): int => [$a['pickup_at']->getTimestamp(), $a['mode'] === PickupMode::Asap ? 0 : 1] <=> [$b['pickup_at']->getTimestamp(), $b['mode'] === PickupMode::Asap ? 0 : 1]);

        foreach ($options as $option) {
            if ($option['pickup_at']->greaterThanOrEqualTo($target)) {
                return [...$option, 'basis' => $basis];
            }
        }

        return [...$options[count($options) - 1], 'basis' => $basis];
    }

    /**
     * The traveller's arrival as the pickup engine may use it. The estimate is the routing provider's figure the
     * cart kept (Module 26 / 27). It is only used while the journey is alive and the arrival still lies ahead;
     * otherwise the journey is reported as stale and nothing is recommended from it.
     *
     * @return array{status: string, arrival_at: CarbonImmutable|null, requires_refresh: bool, source: string|null}|null
     */
    private function journeyContext(CartInspection $inspection, CarbonImmutable $at): ?array
    {
        $cart = $inspection->cart;
        if ($cart->journey_public_id === null) {
            return null;
        }
        $summary = (array) $cart->journey_summary;
        $arrival = isset($summary['estimated_arrival_at']) ? CarbonImmutable::parse((string) $summary['estimated_arrival_at']) : null;
        $source = isset($summary['provider_mode']) ? (string) $summary['provider_mode'] : null;

        if ($inspection->journeyLive && $arrival === null) {
            // The restaurant was never part of that journey's search, so no arrival was ever calculated: nothing to refresh.
            return ['status' => 'NO_ESTIMATE', 'arrival_at' => null, 'requires_refresh' => false, 'source' => $source];
        }
        $fresh = $inspection->journeyLive && $arrival !== null && $arrival->greaterThanOrEqualTo($at);

        return ['status' => $fresh ? 'ACTIVE' : 'STALE', 'arrival_at' => $fresh ? $arrival : null, 'requires_refresh' => ! $fresh, 'source' => $source];
    }

    /**
     * @param  array{status: string, arrival_at: CarbonImmutable|null, requires_refresh: bool, source: string|null}|null  $journey
     * @return list<array{code: string, severity: string, message: string, details: array<string, mixed>}>
     */
    private function journeyIssues(?array $journey): array
    {
        return $journey !== null && $journey['requires_refresh']
            ? [self::issue(Code::JourneyStale, 'Your journey timing is out of date. Plan your journey again for a pickup time that matches your arrival.', [], PickupAvailability::INFO)]
            : [];
    }

    /**
     * Why this restaurant cannot be ordered from at all — or null when it can (being closed right now is not such
     * a reason: a later time may still be offered).
     *
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}|null
     */
    private function restaurantIssue(RestaurantAvailability $state, RestaurantLocation $location): ?array
    {
        return match ($state->reason) {
            null, Reason::ClosedNow => null,
            Reason::MarketUnavailable, Reason::RegionUnavailable, Reason::CityUnavailable, Reason::ServiceAreaUnavailable => self::issue(Code::MarketUnavailable, 'Ordering is not available in this area right now.'),
            Reason::PickupUnavailable => self::issue(Code::PickupDisabled, 'This restaurant does not offer pickup right now.'),
            Reason::NotAcceptingOrders => self::issue(Code::RestaurantNotAccepting, 'This restaurant is not accepting orders right now.', ['resumes_at' => $location->paused_until?->utc()->toIso8601String()]),
            Reason::TemporarilyClosed => self::issue(Code::RestaurantUnavailable, 'This restaurant is temporarily closed.', ['reason' => Reason::TemporarilyClosed->value]),
            default => self::issue(Code::RestaurantUnavailable, 'This restaurant is not available right now.'),
        };
    }

    /**
     * @param  array<int, int>  $usage  unix timestamp of a reserved slot start => units
     */
    private static function used(array $usage, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        $sum = 0;
        foreach ($usage as $timestamp => $units) {
            if ($timestamp >= $from && $timestamp < $to) {
                $sum += $units;
            }
        }

        return $sum;
    }

    /**
     * No configured capacity means no limit — never an invented one.
     */
    private static function capacityState(?int $limit, int $used): PickupCapacityState
    {
        if ($limit === null) {
            return PickupCapacityState::Available;
        }
        if ($used >= $limit) {
            return PickupCapacityState::Full;
        }
        $limited = max(1, (int) floor($limit * (float) config('pickup.capacity.limited_share')));

        return $used > 0 && ($limit - $used) <= $limited ? PickupCapacityState::Limited : PickupCapacityState::Available;
    }

    private static function ceilMinute(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->second > 0 || $instant->microsecond > 0 ? $instant->startOfMinute()->addMinute() : $instant;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{code: string, severity: string, message: string, details: array<string, mixed>}
     */
    public static function issue(Code $code, string $message, array $details = [], string $severity = PickupAvailability::BLOCKING): array
    {
        return ['code' => $code->value, 'severity' => $severity, 'message' => $message, 'details' => array_filter($details, fn ($v): bool => $v !== null)];
    }
}
