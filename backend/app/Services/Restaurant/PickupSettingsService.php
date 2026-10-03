<?php

namespace App\Services\Restaurant;

use App\Auth\Principal;
use App\Enums\OperationalStatus;
use App\Enums\PickupMethod;
use App\Exceptions\ApiException;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationPickupMethod;
use App\Models\RestaurantPickupSettings;
use App\Services\Audit\AuditRecorder;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pickup configuration of a location and its "accepting orders" state.
 *
 * Settings only — every number is configuration of the location within platform limits
 * (config/restaurant.php); nothing is a universal constant. Generating pickup slots, checking slot capacity
 * and estimating the real preparation time are the pickup-availability module's job, and it will read these
 * values. Changing settings never rewrites an order that already exists.
 *
 * What a location may switch on is limited by its market: a pickup mode or method the market has not
 * enabled is refused here (and not offered even if it was saved earlier — PickupRules).
 *
 * Accepting orders is separate from being open: pausing stops NEW FoodOnTheGo orders only. It never cancels
 * or changes orders that were already accepted. A pause may carry a reason and an end time (server clock);
 * when the end time passes, orders are accepted again without anybody doing anything.
 */
final class PickupSettingsService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly RestaurantCatalog $catalog) {}

    /**
     * Starting configuration of a new location: counter pickup, and the modes its market allows.
     */
    public function createDefaults(RestaurantLocation $location, Market $market): RestaurantPickupSettings
    {
        $settings = (new RestaurantPickupSettings)->forceFill([
            'location_id' => $location->getKey(),
            'pickup_enabled' => true,
            'asap_enabled' => PickupRules::modeAllowed($market, 'asap'),
            'scheduled_enabled' => PickupRules::modeAllowed($market, 'scheduled'),
        ]);
        $settings->save();

        RestaurantLocationPickupMethod::query()->create(['location_id' => $location->getKey(), 'method' => PickupMethod::Counter, 'enabled' => true]);

        return $settings->refresh();
    }

    /**
     * @param  array<string, mixed>  $input  version, any setting, and optionally `methods`
     */
    public function update(RestaurantLocation $location, array $input, Principal $actor): RestaurantPickupSettings
    {
        $location->loadMissing(['market', 'pickupMethods']);

        return DB::transaction(function () use ($location, $input, $actor): RestaurantPickupSettings {
            $settings = RestaurantPickupSettings::query()->where('location_id', $location->getKey())->lockForUpdate()->first()
                ?? $this->createDefaults($location, $location->market);
            $settings->assertVersion((int) $input['version']);

            $settings->fill(array_intersect_key($input, array_flip($settings->getFillable())));
            $methods = $this->methods($location, $input['methods'] ?? null);
            $this->assertInvariants($location->market, $settings, $methods);

            $changes = [];
            foreach (array_keys($settings->getDirty()) as $key) {
                $changes[$key] = ['from' => $settings->getOriginal($key), 'to' => $settings->getAttribute($key)];
            }

            if (isset($input['methods'])) {
                $before = $this->describe($location->pickupMethods->all());
                foreach ($methods as $method) {
                    RestaurantLocationPickupMethod::query()->updateOrCreate(
                        ['location_id' => $location->getKey(), 'method' => $method['method']->value],
                        ['enabled' => $method['enabled'], 'instructions' => $method['instructions'], 'requires_vehicle_info' => $method['requires_vehicle_info']],
                    );
                }
                $after = $this->describe(RestaurantLocationPickupMethod::query()->where('location_id', $location->getKey())->orderBy('id')->get()->all());
                if ($before !== $after) {
                    $changes['methods'] = ['from' => $before, 'to' => $after];
                }
            }

            if ($changes === []) {
                return $settings;
            }

            $settings->setAttribute('version', (int) $settings->version + 1);
            $settings->save();
            $this->audit->record('restaurant_location.pickup_settings_changed', $location, $actor, $changes, null, (int) $location->market_id);
            $this->catalog->flush();

            return $settings->refresh();
        });
    }

    /**
     * Accepting orders, pause and the "temporarily closed" switch — the only fields this endpoint can touch.
     *
     * @param  array{accepting_orders?: bool, pause_reason?: string|null, paused_until?: string|null, operational_status?: string}  $input
     */
    public function setAvailability(RestaurantLocation $location, array $input, Principal $actor): RestaurantLocation
    {
        return DB::transaction(function () use ($location, $input, $actor): RestaurantLocation {
            $locked = RestaurantLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $before = ['accepting_orders' => $locked->accepting_orders, 'pause_reason' => $locked->pause_reason, 'paused_until' => $locked->paused_until?->toIso8601String(), 'operational_status' => $locked->operational_status->value];

            if (array_key_exists('accepting_orders', $input)) {
                if ($input['accepting_orders']) {
                    $locked->forceFill(['accepting_orders' => true, 'pause_reason' => null, 'paused_at' => null, 'paused_until' => null]);
                } else {
                    $locked->forceFill([
                        'accepting_orders' => false,
                        'pause_reason' => PlainText::clean($input['pause_reason'] ?? null, 'pause_reason'),
                        'paused_at' => $locked->accepting_orders ? now() : ($locked->paused_at ?? now()),
                        'paused_until' => $this->pauseEnd($input['paused_until'] ?? null),
                    ]);
                }
            } elseif (array_key_exists('pause_reason', $input) || array_key_exists('paused_until', $input)) {
                throw ValidationException::withMessages(['accepting_orders' => ['A pause reason or end time can only be sent together with accepting_orders = false.']]);
            }

            if (isset($input['operational_status'])) {
                $locked->setAttribute('operational_status', OperationalStatus::from($input['operational_status']));
            }

            $after = ['accepting_orders' => $locked->accepting_orders, 'pause_reason' => $locked->pause_reason, 'paused_until' => $locked->paused_until?->toIso8601String(), 'operational_status' => $locked->operational_status->value];
            $changes = [];
            foreach ($after as $key => $value) {
                if ($before[$key] !== $value) {
                    $changes[$key] = ['from' => $before[$key], 'to' => $value];
                }
            }
            if ($changes === []) {
                return $locked;
            }

            $locked->save();
            $action = isset($changes['accepting_orders']) || isset($changes['paused_until']) || isset($changes['pause_reason']) ? 'restaurant_location.accepting_orders_changed' : 'restaurant_location.operational_status_changed';
            $this->audit->record($action, $locked, $actor, $changes, null, (int) $locked->market_id);
            $this->catalog->flush();

            return $locked;
        });
    }

    /**
     * The end of a pause: in the future and not further away than the platform allows (server clock).
     */
    private function pauseEnd(?string $until): ?CarbonImmutable
    {
        if ($until === null) {
            return null;
        }

        $end = CarbonImmutable::parse($until);
        $max = (int) config('restaurant.limits.pause_max_hours');

        if ($end->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['paused_until' => ['The end of a pause must be in the future.']]);
        }
        if ($end->greaterThan(now()->addHours($max))) {
            throw ValidationException::withMessages(['paused_until' => ["A pause can last at most {$max} hours. For longer, mark the restaurant as temporarily closed."]]);
        }

        return $end;
    }

    /**
     * The methods as they will be after the update: stored ones, overlaid with the submitted ones.
     *
     * @param  list<array<string, mixed>>|null  $submitted
     * @return array<string, array{method: PickupMethod, enabled: bool, instructions: string|null, requires_vehicle_info: bool}>
     */
    private function methods(RestaurantLocation $location, ?array $submitted): array
    {
        $methods = [];
        foreach ($location->pickupMethods as $stored) {
            $methods[$stored->method->value] = ['method' => $stored->method, 'enabled' => $stored->enabled, 'instructions' => $stored->instructions, 'requires_vehicle_info' => $stored->requires_vehicle_info];
        }

        foreach ($submitted ?? [] as $index => $row) {
            $method = PickupMethod::from($row['method']);
            if (($row['enabled'] ?? false) && ! PickupRules::methodAllowed($location->market, $method)) {
                throw new ApiException(409, 'pickup_method_not_available', 'This pickup method is not available in this market.', ['method' => $method->value]);
            }
            $methods[$method->value] = [
                'method' => $method,
                'enabled' => (bool) ($row['enabled'] ?? false),
                'instructions' => PlainText::clean($row['instructions'] ?? null, "methods.{$index}.instructions"),
                'requires_vehicle_info' => (bool) ($row['requires_vehicle_info'] ?? ($method !== PickupMethod::Counter)),
            ];
        }

        return $methods;
    }

    /**
     * Rules that span several fields. Field-level ranges are validated by the controller.
     *
     * @param  array<string, array{method: PickupMethod, enabled: bool, instructions: string|null, requires_vehicle_info: bool}>  $methods
     */
    private function assertInvariants(Market $market, RestaurantPickupSettings $settings, array $methods): void
    {
        foreach (['asap' => 'asap_enabled', 'scheduled' => 'scheduled_enabled'] as $mode => $field) {
            if ($settings->getAttribute($field) && $settings->isDirty($field) && ! PickupRules::modeAllowed($market, $mode)) {
                throw new ApiException(409, 'pickup_mode_not_available', 'This pickup mode is not available in this market.', ['mode' => $mode]);
            }
        }

        if ($settings->pickup_enabled) {
            if (! $settings->asap_enabled && ! $settings->scheduled_enabled) {
                throw ValidationException::withMessages(['asap_enabled' => ['With pickup enabled, at least one of ASAP or scheduled pickup must be on.']]);
            }
            if (array_filter($methods, fn (array $m): bool => $m['enabled']) === []) {
                throw ValidationException::withMessages(['methods' => ['With pickup enabled, at least one pickup method must be enabled.']]);
            }
        }

        if ($settings->scheduled_enabled) {
            if ($settings->schedule_horizon_minutes < max(1, $settings->minimum_lead_minutes)) {
                throw ValidationException::withMessages(['schedule_horizon_minutes' => ['Customers must be able to schedule at least as far ahead as the minimum lead time.']]);
            }
            if ($settings->schedule_horizon_minutes < $settings->slot_interval_minutes) {
                throw ValidationException::withMessages(['schedule_horizon_minutes' => ['The scheduling horizon must be at least one slot long.']]);
            }
        }
    }

    /**
     * @param  list<RestaurantLocationPickupMethod>  $methods
     * @return list<string>
     */
    private function describe(array $methods): array
    {
        return array_map(fn (RestaurantLocationPickupMethod $m): string => $m->method->value.($m->enabled ? ':on' : ':off').($m->instructions === null ? '' : ' ('.$m->instructions.')'), $methods);
    }
}
