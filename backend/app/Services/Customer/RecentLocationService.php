<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerRecentLocation;
use App\Support\Geo\Location;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recent places a customer explicitly chose (Module 25) — the "Recent" shortcuts of journey planning.
 *
 * Privacy policy (config/customer.php): only places the customer selected in the app are recorded (never
 * background positions); the list is bounded to the newest `limits.recent_locations`, deduplicated by place id
 * or rounded coordinates, clearable by the customer in one call, and pruned after `recent_locations_retention_days`.
 * Full journey history belongs to the journey module (Module 26).
 */
final class RecentLocationService
{
    /**
     * @param  array<string, mixed>  $input  validated: label, lat, lng, optional formatted_address, place_provider, place_id, country_code, timezone
     */
    public function record(Customer $customer, array $input): CustomerRecentLocation
    {
        $point = new Location((float) $input['lat'], (float) $input['lng'], $input['country_code'] ?? null);
        $provider = $input['place_provider'] ?? null;
        $placeId = $input['place_id'] ?? null;
        $key = $placeId !== null ? 'place:'.($provider ?? 'unknown').':'.$placeId : sprintf('geo:%.4f,%.4f', $point->latitude, $point->longitude);
        $label = PlainText::clean((string) $input['label'], 'label') ?? 'Recent place';
        $formatted = PlainText::clean(isset($input['formatted_address']) ? (string) $input['formatted_address'] : null, 'formatted_address');

        return DB::transaction(function () use ($customer, $point, $provider, $placeId, $key, $label, $formatted, $input): CustomerRecentLocation {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['recent:'.$customer->getKey()]);
            $existing = CustomerRecentLocation::query()->where('customer_id', $customer->getKey())->where('dedupe_key', $key)->first();
            if ($existing !== null) {
                $existing->forceFill(['label' => $label, 'formatted_address' => $formatted, 'times_used' => (int) $existing->times_used + 1, 'last_used_at' => now()])->save();
                $existing->updateSpatial('location', CustomerRecentLocation::pointExpression($point->latitude, $point->longitude));
                $row = $existing->reload();
            } else {
                $row = (new CustomerRecentLocation)->forceFill([
                    'customer_id' => $customer->getKey(), 'dedupe_key' => $key, 'label' => $label, 'formatted_address' => $formatted,
                    'place_provider' => $provider, 'place_id' => $placeId, 'country_code' => $input['country_code'] ?? null, 'timezone' => $input['timezone'] ?? null,
                    'times_used' => 1, 'last_used_at' => now(),
                ])->insertWithSpatial(['location' => CustomerRecentLocation::pointExpression($point->latitude, $point->longitude)]);
            }
            // Keep only the newest N.
            $keep = CustomerRecentLocation::query()->withoutGlobalScope('coordinates')->where('customer_id', $customer->getKey())->orderByDesc('last_used_at')->orderByDesc('id')->limit((int) config('customer.limits.recent_locations'))->pluck('id');
            CustomerRecentLocation::query()->where('customer_id', $customer->getKey())->whereNotIn('id', $keep)->delete();

            return $row;
        });
    }

    /**
     * @return Collection<int, CustomerRecentLocation>
     */
    public function list(Customer $customer): Collection
    {
        return CustomerRecentLocation::query()->where('customer_id', $customer->getKey())->orderByDesc('last_used_at')->orderByDesc('id')->limit((int) config('customer.limits.recent_locations'))->get();
    }

    public function clear(Customer $customer): int
    {
        return CustomerRecentLocation::query()->where('customer_id', $customer->getKey())->delete();
    }

    /**
     * Retention: rows not used within the configured number of days are removed (scheduled daily).
     */
    public function prune(): int
    {
        return CustomerRecentLocation::query()->where('last_used_at', '<', now()->subDays((int) config('customer.recent_locations_retention_days')))->delete();
    }
}
