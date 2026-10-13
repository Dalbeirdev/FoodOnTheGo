<?php

namespace App\Services\Analytics;

use App\Models\Customer;
use App\Services\Market\MarketContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Behavioural events sent by the web and Android applications (Module 38).
 *
 * What a client sends is TELEMETRY: which screens a session reached on the way to an order. It is never a fact about
 * a payment, an order or an amount — the registry has no such event, and nothing stored here is read by any domain.
 *
 * The rules of this door:
 *   - a name or a property outside the registry is refused (that one event; the rest of the batch is stored);
 *   - the same event id twice is stored once;
 *   - a customer is stored as a keyed hash, a journey as a keyed hash; nothing else personal is accepted at all;
 *   - a checkout reference is kept only when the checkout is the signed-in caller's own;
 *   - the event's time is the sender's clock, bounded by the server's.
 */
final class EventIngestService
{
    public function __construct(
        private readonly EventRegistry $registry,
        private readonly Pseudonymizer $pseudonyms,
        private readonly AnalyticsMetrics $metrics,
        private readonly MarketContext $markets,
    ) {}

    /**
     * @param  array{session: string, platform: string, app_version?: ?string, consent?: bool, events: list<array<string, mixed>>}  $batch
     * @return array{accepted: int, duplicates: int, rejected: list<array{id: ?string, reason: string}>, stored: bool}
     */
    public function ingest(array $batch, ?Customer $customer): array
    {
        $this->metrics->record('ingest_requests');
        if (! (bool) config('analytics.events.enabled')) {
            $this->metrics->record('ingest_disabled');

            return ['accepted' => 0, 'duplicates' => 0, 'rejected' => [], 'stored' => false];
        }
        // The sender says the person did not agree to behavioural analytics: nothing is kept.
        if (($batch['consent'] ?? true) === false) {
            $this->metrics->record('ingest_consent_withheld');

            return ['accepted' => 0, 'duplicates' => 0, 'rejected' => [], 'stored' => false];
        }

        $now = CarbonImmutable::now('UTC');
        $rejected = [];
        $candidates = [];
        foreach ($batch['events'] as $event) {
            $id = is_string($event['id'] ?? null) && Str::isUuid($event['id']) ? strtolower($event['id']) : null;
            $reason = $this->refuse($event, $id, $now);
            if ($reason !== null) {
                $rejected[] = ['id' => $id, 'reason' => $reason];

                continue;
            }
            $candidates[] = $event + ['id' => $id];
        }

        $locations = $this->locations($candidates);
        $checkouts = $this->ownCheckouts($candidates, $customer);
        $defaultMarket = $this->defaultMarketId();
        $customerKey = $customer === null ? null : $this->pseudonyms->customer((int) $customer->getKey());

        $rows = [];
        foreach ($candidates as $event) {
            $definition = (array) $this->registry->find((string) $event['name']);
            $required = (array) ($definition['required'] ?? []);
            $allowed = (array) ($definition['refs'] ?? []);
            $refs = array_intersect_key((array) ($event['refs'] ?? []), array_flip($allowed));

            $location = isset($refs['restaurant']) ? ($locations[strtolower((string) $refs['restaurant'])] ?? null) : null;
            $checkout = isset($refs['checkout']) && isset($checkouts[strtolower((string) $refs['checkout'])]) ? strtolower((string) $refs['checkout']) : null;
            if ((in_array('restaurant', $required, true) && $location === null) || (in_array('checkout', $required, true) && $checkout === null)) {
                $rejected[] = ['id' => $event['id'], 'reason' => 'unknown_reference'];

                continue;
            }
            [$properties] = $this->registry->properties((string) $event['name'], (array) ($event['properties'] ?? []));
            $rows[$event['id']] = [
                'event_id' => $event['id'],
                'event_name' => (string) $event['name'],
                'schema_version' => (int) config('analytics.events.schema_version'),
                'source' => 'CLIENT',
                'platform' => (string) $batch['platform'],
                'app_version' => isset($batch['app_version']) ? Str::limit((string) $batch['app_version'], 24, '') : null,
                'occurred_at' => $this->occurredAt($event, $now),
                'received_at' => $now,
                'session_id' => strtolower((string) $batch['session']),
                'customer_key' => $customerKey,
                'market_id' => $location['market_id'] ?? $defaultMarket,
                'location_id' => $location['id'] ?? null,
                'journey_key' => isset($refs['journey']) ? $this->pseudonyms->journey((string) $refs['journey']) : null,
                'checkout_ref' => $checkout,
                'item_ref' => isset($refs['item']) ? strtolower((string) $refs['item']) : null,
                'properties' => json_encode((object) $properties),
            ];
        }

        // In a transaction of its own (a savepoint when the caller has one): a refused write must stay analytics' problem.
        $stored = $rows === [] ? 0 : (int) DB::transaction(fn (): int => DB::table('analytics_events')->insertOrIgnore(array_values($rows)));
        $duplicates = count($rows) - $stored;
        $this->metrics->record('events_accepted', $stored);
        $this->metrics->record('events_duplicate', $duplicates);
        $this->metrics->record('events_rejected', count($rejected));

        return ['accepted' => $stored, 'duplicates' => $duplicates, 'rejected' => $rejected, 'stored' => true];
    }

    /**
     * Why an event cannot be stored — before anything is looked up.
     *
     * @param  array<string, mixed>  $event
     */
    private function refuse(array $event, ?string $id, CarbonImmutable $now): ?string
    {
        if ($id === null) {
            return 'invalid_id';
        }
        $name = $event['name'] ?? null;
        if (! is_string($name) || $this->registry->find($name) === null) {
            return 'unknown_event';
        }
        if (! $this->registry->isClientEvent($name)) {
            // A server-recorded event (and anything that sounds like a fact of a domain) is not a client's to send.
            return 'not_a_client_event';
        }
        $definition = (array) $this->registry->find($name);
        $refs = $event['refs'] ?? [];
        if (! is_array($refs) || array_diff(array_keys($refs), EventRegistry::REFS) !== [] || array_diff(array_keys($refs), (array) $definition['refs']) !== []) {
            return 'unknown_reference_kind';
        }
        foreach ($refs as $kind => $value) {
            $valid = is_string($value) && ($kind === 'restaurant' ? (Str::isUuid($value) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) === 1) && strlen($value) <= 120 : Str::isUuid($value));
            if (! $valid) {
                return 'invalid_reference';
            }
        }
        foreach ((array) $definition['required'] as $kind) {
            if (! isset($refs[$kind])) {
                return 'missing_reference';
            }
        }
        $properties = $event['properties'] ?? [];
        if (! is_array($properties)) {
            return 'invalid_property';
        }
        [, $problem] = $this->registry->properties($name, $properties);
        if ($problem !== null) {
            return $problem;
        }
        $occurred = $this->parse($event['occurred_at'] ?? null);
        if ($occurred === null) {
            return 'invalid_time';
        }
        if ($occurred->lt($now->subHours((int) config('analytics.events.max_age_hours')))) {
            return 'too_old';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function occurredAt(array $event, CarbonImmutable $now): CarbonImmutable
    {
        $occurred = $this->parse($event['occurred_at'] ?? null) ?? $now;

        // A clock that runs ahead does not put an event in the future.
        return $occurred->gt($now->addSeconds((int) config('analytics.events.max_future_seconds'))) ? $now : $occurred;
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The restaurants the events name, by the reference as it was sent (public id or slug).
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, array{id: int, market_id: int}>
     */
    private function locations(array $events): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn (array $e): ?string => isset($e['refs']['restaurant']) ? strtolower((string) $e['refs']['restaurant']) : null, $events))));
        if ($refs === []) {
            return [];
        }
        $ids = array_values(array_filter($refs, fn (string $r): bool => Str::isUuid($r)));
        $slugs = array_values(array_diff($refs, $ids));
        $found = [];
        $rows = DB::table('restaurant_locations')->where(fn ($q) => $q->whereIn('public_id', $ids === [] ? [null] : $ids)->orWhereIn('slug', $slugs === [] ? [null] : $slugs))->get(['id', 'public_id', 'slug', 'market_id']);
        foreach ($rows as $row) {
            $value = ['id' => (int) $row->id, 'market_id' => (int) $row->market_id];
            $found[strtolower((string) $row->public_id)] = $value;
            $found[(string) $row->slug] = $value;
        }

        return $found;
    }

    /**
     * The checkouts the events name that are the caller's own. A guest has none.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, true>
     */
    private function ownCheckouts(array $events, ?Customer $customer): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn (array $e): ?string => isset($e['refs']['checkout']) ? strtolower((string) $e['refs']['checkout']) : null, $events))));
        if ($refs === [] || $customer === null) {
            return [];
        }

        return DB::table('checkout_sessions')->where('customer_id', $customer->getKey())->whereIn('public_id', $refs)->pluck('public_id')
            ->mapWithKeys(fn ($id): array => [strtolower((string) $id) => true])->all();
    }

    private function defaultMarketId(): ?int
    {
        try {
            return (int) $this->markets->current()->getKey();
        } catch (\Throwable) {
            return null;
        }
    }
}
