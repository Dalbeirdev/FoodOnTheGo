<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Restaurant\Concerns\LoadsRestaurantLocations;
use App\Http\Controllers\Controller;
use App\Http\Resources\Restaurant\Concerns\PresentsRestaurant;
use App\Http\Resources\Restaurant\SpecialHourResource;
use App\Models\RestaurantLocation;
use App\Models\RestaurantSpecialHour;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Restaurant\RestaurantAvailabilityService;
use App\Services\Restaurant\RestaurantHoursService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Opening hours of a location: the weekly schedule (replaced as a whole, atomically) and special dates.
 * All times are wall-clock "HH:MM" in the location's own time zone; the rules (several periods a day,
 * overnight periods, no overlaps, special dates replacing regular hours) live in RestaurantHoursService.
 */
class HoursController extends Controller
{
    use LoadsRestaurantLocations, PresentsRestaurant;

    private const PERIOD = ['required', 'date_format:H:i'];

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly RestaurantHoursService $hours,
    ) {}

    public function show(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileView);

        return $this->payload($this->fresh($location));
    }

    /**
     * PUT replaces the whole week in one transaction: either every period is saved or none. An empty list
     * means "closed all week". `version` is the hours version the client edited (409 when it is stale).
     */
    public function replace(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantHoursManage);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'periods' => ['present', 'array', 'max:'.(7 * (int) config('restaurant.limits.periods_per_day'))],
            'periods.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'periods.*.opens_at' => self::PERIOD,
            'periods.*.closes_at' => self::PERIOD,
        ]);

        $this->hours->replaceWeekly($location, array_values($input['periods']), (int) $input['version'], $request->user());

        return $this->payload($this->fresh($location));
    }

    public function specialIndex(Request $request, RestaurantLocation $location): AnonymousResourceCollection
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantProfileView);

        // Upcoming dates and the last week, soonest first; older ones are history.
        return SpecialHourResource::collection(RestaurantSpecialHour::query()->with('periods')
            ->where('location_id', $location->getKey())->where('date', '>=', now($location->timezone)->subDays(7)->toDateString())->orderBy('date')->get());
    }

    public function specialStore(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantHoursManage);

        $special = $this->hours->createSpecial($location, $this->specialInput($request, creating: true), $request->user());

        return (new SpecialHourResource($special))->response()->setStatusCode(201);
    }

    public function specialUpdate(Request $request, RestaurantLocation $location, RestaurantSpecialHour $specialHour): SpecialHourResource
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantHoursManage);
        $this->assertOwned($location, $specialHour);

        return new SpecialHourResource($this->hours->updateSpecial($location, $specialHour, $this->specialInput($request, creating: false), $request->user()));
    }

    public function specialDestroy(Request $request, RestaurantLocation $location, RestaurantSpecialHour $specialHour): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantHoursManage);
        $this->assertOwned($location, $specialHour);

        $this->hours->deleteSpecial($location, $specialHour, $request->user());

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function specialInput(Request $request, bool $creating): array
    {
        $input = $request->validate([
            'date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'is_closed' => ['required', 'boolean'],
            'periods' => ['sometimes', 'array', 'max:'.(int) config('restaurant.limits.periods_per_day')],
            'periods.*.opens_at' => self::PERIOD,
            'periods.*.closes_at' => self::PERIOD,
            'public_note' => ['sometimes', 'nullable', 'string', 'max:160'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $input['is_closed'] = filter_var($input['is_closed'], FILTER_VALIDATE_BOOLEAN);

        return $input;
    }

    /**
     * A special date of another location — even one of the same organization — does not exist here.
     */
    private function assertOwned(RestaurantLocation $location, RestaurantSpecialHour $special): void
    {
        if ((int) $special->location_id !== (int) $location->getKey()) {
            throw ApiException::notFound('not_found', 'The requested resource was not found.');
        }
    }

    private function payload(RestaurantLocation $location): JsonResponse
    {
        return response()->json([
            'location_id' => $location->public_id,
            'version' => (int) $location->hours_version,
            'timezone' => $location->timezone,
            'weekly' => $this->weeklyHours($location),
            'special' => $this->specialHours($location, internal: true),
            // What these hours mean right now, calculated by the backend in the location's time zone.
            'availability' => app(RestaurantAvailabilityService::class)->evaluate($location)->forStaff(),
        ]);
    }
}
