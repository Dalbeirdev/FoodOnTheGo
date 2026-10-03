<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\OperationalStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Restaurant\Concerns\LoadsRestaurantLocations;
use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\PickupSettingsResource;
use App\Http\Resources\Restaurant\RestaurantLocationResource;
use App\Models\RestaurantImage;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\PickupSettingsService;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Restaurant\RestaurantProfileService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A location for the staff who run it: profile, accepting orders / pause, pickup settings and image
 * metadata. Every action first asks RestaurantAccess whether the location is the caller's at all (404
 * otherwise) and whether they hold the permission for it (403 otherwise).
 *
 * What a restaurant must not decide for itself — approval status, organization, market, coordinates,
 * currency, time zone, slug — is refused here even when sent (`prohibited`), and no service reachable from
 * this controller can write it.
 */
class LocationController extends Controller
{
    use LoadsRestaurantLocations;

    /** Fields of a location that only an administrator changes. Sending one is an error, not a silent no-op. */
    private const PROTECTED = [
        'status', 'slug', 'organization_id', 'market_id', 'region_id', 'city_id', 'service_area_id', 'latitude', 'longitude', 'location',
        'timezone', 'currency', 'locale', 'formatted_address', 'address_line1', 'postal_code', 'status_note', 'rejection_category', 'approved_at',
    ];

    public function __construct(private readonly RestaurantAccess $access) {}

    public function show(Request $request, RestaurantLocation $location): RestaurantLocationResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileView);

        return new RestaurantLocationResource($this->fresh($location));
    }

    public function updateProfile(Request $request, RestaurantLocation $location, RestaurantProfileService $profiles): RestaurantLocationResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileManage);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'branch_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('restaurant.limits.description')],
            'pickup_instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            'price_level' => ['sometimes', 'nullable', 'integer', 'between:1,4'],
            'cuisines' => ['sometimes', 'array', 'max:'.(int) config('restaurant.limits.cuisines')],
            'cuisines.*' => ['string', 'max:60', 'distinct'],
            'features' => ['sometimes', 'array', 'max:'.(int) config('restaurant.limits.features')],
            'features.*' => ['string', 'max:60', 'distinct'],
            'accepting_orders' => ['prohibited'],
            'operational_status' => ['prohibited'],
            ...array_fill_keys(self::PROTECTED, ['prohibited']),
        ], ['prohibited' => 'This field cannot be changed here.']);

        if (array_key_exists('email', $input)) {
            $input['public_email'] = $input['email'];
            unset($input['email']);
        }

        return new RestaurantLocationResource($this->fresh($profiles->update($location, $input, $request->user())));
    }

    /**
     * Accepting orders, pause (reason, end time) and the "temporarily closed" switch. A switch, not an edit:
     * the last request wins, so there is no version to send. Pausing never touches orders already accepted.
     */
    public function updateAvailability(Request $request, RestaurantLocation $location, PickupSettingsService $pickup): RestaurantLocationResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantSettingsManage);

        $input = $request->validate([
            'accepting_orders' => ['required_without:operational_status', 'boolean'],
            'pause_reason' => ['sometimes', 'nullable', 'string', 'max:200'],
            'paused_until' => ['sometimes', 'nullable', 'date'],
            'operational_status' => ['required_without:accepting_orders', Rule::enum(OperationalStatus::class)],
        ]);
        if (array_key_exists('accepting_orders', $input)) {
            $input['accepting_orders'] = filter_var($input['accepting_orders'], FILTER_VALIDATE_BOOLEAN);
        }

        return new RestaurantLocationResource($this->fresh($pickup->setAvailability($location, $input, $request->user())));
    }

    public function pickupSettings(Request $request, RestaurantLocation $location): PickupSettingsResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileView);

        return new PickupSettingsResource($this->fresh($location));
    }

    public function updatePickupSettings(Request $request, RestaurantLocation $location, PickupSettingsService $pickup): PickupSettingsResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantPickupSettingsManage);

        $limit = fn (string $key): string => 'between:'.implode(',', (array) config("restaurant.limits.{$key}"));
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'asap_enabled' => ['sometimes', 'boolean'],
            'scheduled_enabled' => ['sometimes', 'boolean'],
            'default_prep_minutes' => ['sometimes', 'integer', $limit('prep_minutes')],
            'minimum_lead_minutes' => ['sometimes', 'integer', $limit('lead_minutes')],
            'buffer_minutes' => ['sometimes', 'integer', $limit('buffer_minutes')],
            'order_cutoff_minutes' => ['sometimes', 'integer', $limit('cutoff_minutes')],
            'schedule_horizon_minutes' => ['sometimes', 'integer', $limit('schedule_horizon_minutes')],
            'slot_interval_minutes' => ['sometimes', 'integer', Rule::in((array) config('restaurant.limits.slot_intervals'))],
            'capacity_per_slot' => ['sometimes', 'nullable', 'integer', $limit('capacity_per_slot')],
            'methods' => ['sometimes', 'array', 'min:1', 'max:10'],
            'methods.*.method' => ['required', 'string', 'distinct', Rule::in(array_keys((array) config('restaurant.pickup_methods')))],
            'methods.*.enabled' => ['required', 'boolean'],
            'methods.*.instructions' => ['sometimes', 'nullable', 'string', 'max:300'],
            'methods.*.requires_vehicle_info' => ['sometimes', 'boolean'],
        ]);
        foreach (['pickup_enabled', 'asap_enabled', 'scheduled_enabled'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $pickup->update($location, $input, $request->user());

        return new PickupSettingsResource($this->fresh($location));
    }

    /**
     * Alternative text and order of an image. The image must belong to this location — an id of another
     * restaurant's image is a 404 here.
     */
    public function updateImage(Request $request, RestaurantLocation $location, RestaurantImage $image, RestaurantProfileService $profiles): RestaurantLocationResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileManage);
        $this->assertOwned($location, $image);

        $input = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'display_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);
        $profiles->updateImage($location, $image, $input, $request->user());

        return new RestaurantLocationResource($this->fresh($location));
    }

    public function archiveImage(Request $request, RestaurantLocation $location, RestaurantImage $image, RestaurantProfileService $profiles): RestaurantLocationResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileManage);
        $this->assertOwned($location, $image);

        $profiles->archiveImage($location, $image, $request->user());

        return new RestaurantLocationResource($this->fresh($location));
    }

    private function assertOwned(RestaurantLocation $location, RestaurantImage $image): void
    {
        if ((int) $image->location_id !== (int) $location->getKey()) {
            throw ApiException::notFound('not_found', 'The requested resource was not found.');
        }
    }
}
