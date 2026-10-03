<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Auth\AccessControl;
use App\Http\Controllers\Api\Restaurant\Concerns\LoadsRestaurantLocations;
use App\Http\Controllers\Controller;
use App\Http\Resources\Menu\MenuPresenter;
use App\Http\Resources\Restaurant\RestaurantLocationResource;
use App\Models\Cuisine;
use App\Models\DietaryTag;
use App\Models\RestaurantFeature;
use App\Models\RestaurantMembership;
use App\Models\RestaurantUser;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Restaurant\RestaurantAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the signed-in restaurant user works with: the organizations they belong to and the locations they may
 * open. The list comes from their ACTIVE memberships in the database — a client never tells the backend which
 * organization or location it "is".
 */
class ContextController extends Controller
{
    use LoadsRestaurantLocations;

    /**
     * `default_location_id` is deterministic: the first authorized location, approved ones before the others,
     * then the oldest — so the same person always lands on the same location until they choose another.
     */
    public function show(Request $request, RestaurantAccess $access, AccessControl $control, RestaurantAvailabilityService $availability): JsonResponse
    {
        /** @var RestaurantUser $user */
        $user = $request->user();

        $locations = $access->locations($user)->with($this->locationRelations())
            ->orderByRaw("case when restaurant_locations.status = 'APPROVED' then 0 else 1 end")
            ->orderBy('restaurant_locations.id')
            ->get();
        $availability->preloadCoverage($locations);

        return response()->json([
            'organizations' => $access->memberships($user)->map(fn (RestaurantMembership $membership): array => [
                'id' => $membership->organization->public_id,
                'name' => $membership->organization->display_name,
                'legal_name' => $membership->organization->legal_name,
                'status' => $membership->organization->status->value,
                'status_note' => $membership->organization->status_note,
                'membership' => [
                    'id' => $membership->public_id,
                    'role' => ['code' => $membership->role->code, 'name' => $membership->role->name],
                    'all_locations' => $membership->all_locations,
                ],
                // Held for the whole organization (empty for a membership limited to some locations).
                'permissions' => $control->permissionsIn($user, $membership->organization->scope()),
            ])->values()->all(),
            'locations' => RestaurantLocationResource::collection($locations)->resolve($request),
            'default_location_id' => $locations->first()?->public_id,
        ]);
    }

    /**
     * The options a profile is built from: active cuisines and features, and how many may be chosen.
     */
    public function taxonomy(): JsonResponse
    {
        return response()->json([
            'cuisines' => Cuisine::query()->active()->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (Cuisine $c): array => ['code' => $c->code, 'name' => $c->name])->all(),
            'features' => RestaurantFeature::query()->active()->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (RestaurantFeature $f): array => ['code' => $f->code, 'name' => $f->name, 'category' => $f->category])->all(),
            // Module 24: the labels a restaurant may attach to menu items, and the menu limits.
            'dietary_tags' => DietaryTag::query()->active()->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (DietaryTag $t): array => ['code' => $t->code, 'name' => $t->name, 'kind' => $t->kind])->all(),
            'menu' => MenuPresenter::limits(),
            'limits' => [
                'cuisines' => (int) config('restaurant.limits.cuisines'),
                'features' => (int) config('restaurant.limits.features'),
                'description' => (int) config('restaurant.limits.description'),
                'periods_per_day' => (int) config('restaurant.limits.periods_per_day'),
                'pause_max_hours' => (int) config('restaurant.limits.pause_max_hours'),
            ],
        ]);
    }
}
