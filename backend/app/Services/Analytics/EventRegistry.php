<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

/**
 * The registry of behavioural events (Module 38) — `analytics.events.registry` read in one place.
 *
 * An event that is not registered does not exist: the ingestion endpoint refuses the name, and a registered event
 * with a property that is not listed is refused too. There is no payment_verified, order_created, order_completed or
 * refund_completed in the registry on purpose — those are facts of their own domains and no client can report them.
 */
final class EventRegistry
{
    public const REFS = ['restaurant', 'journey', 'item', 'checkout'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return (array) config('analytics.events.registry');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    public function isClientEvent(string $name): bool
    {
        return ($this->find($name)['source'] ?? null) === 'CLIENT';
    }

    /**
     * Event names by funnel stage (1–8).
     *
     * @return array<int, string>
     */
    public function stageEvents(): array
    {
        $stages = [];
        foreach ((array) config('analytics.funnel.stages') as $number => $stage) {
            if (($stage['event'] ?? null) !== null) {
                $stages[(int) $number] = (string) $stage['event'];
            }
        }
        ksort($stages);

        return $stages;
    }

    /**
     * The properties of an event as they may be stored, or the reason they may not.
     *
     * @param  array<string, mixed>  $properties
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public function properties(string $name, array $properties): array
    {
        $rules = (array) ($this->find($name)['properties'] ?? []);
        $clean = [];
        foreach ($properties as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $rules)) {
                return [[], 'unknown_property'];
            }
            $checked = $this->value((string) $rules[$key], $value);
            if ($checked === null) {
                return [[], 'invalid_property'];
            }
            $clean[$key] = $checked[0];
        }
        if (strlen((string) json_encode($clean)) > (int) config('analytics.events.max_properties_bytes')) {
            return [[], 'properties_too_large'];
        }

        return [$clean, null];
    }

    /**
     * @return array{0: mixed}|null the accepted value, boxed (false and 0 are values)
     */
    private function value(string $rule, mixed $value): ?array
    {
        [$type, $argument] = array_pad(explode(':', $rule, 2), 2, '');

        return match ($type) {
            'bool' => is_bool($value) ? [$value] : null,
            'int' => is_int($value) && $value >= (int) Str::before($argument, ',') && $value <= (int) Str::after($argument, ',') ? [$value] : null,
            'enum' => is_string($value) && in_array($value, explode('|', $argument), true) ? [$value] : null,
            // Server-recorded only: a band label, a short code, an internal id, a short list of internal ids.
            'band' => is_string($value) && preg_match('/\A[0-9A-Za-z+\-]{1,12}\z/', $value) === 1 ? [$value] : null,
            'code' => is_string($value) && preg_match('/\A[A-Z0-9_]{1,40}\z/', $value) === 1 ? [$value] : null,
            'id' => is_int($value) && $value > 0 ? [$value] : null,
            'ids' => is_array($value) && count($value) <= 20 && array_filter($value, fn ($v): bool => ! is_int($v) || $v <= 0) === [] ? [array_values($value)] : null,
            default => null,
        };
    }
}
