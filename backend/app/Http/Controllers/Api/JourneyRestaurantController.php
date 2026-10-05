<?php

namespace App\Http\Controllers\Api;

use App\Enums\JourneySort;
use App\Enums\PickupMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Journey\JourneyRestaurantResource;
use App\Models\Journey;
use App\Services\Journey\DiscoveryFilters;
use App\Services\Journey\JourneyPolicy;
use App\Services\Journey\RouteDiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * GET /journeys/{journey}/restaurants — restaurants along the owner's journey with their actual detour and
 * arrival estimate, bounded and paginated. Paging reuses the cached candidate set (detours are never
 * recalculated per page); every page re-checks the restaurants' current status.
 */
class JourneyRestaurantController extends Controller
{
    public function index(Request $request, RouteDiscoveryService $discovery, JourneyPolicy $policies): AnonymousResourceCollection
    {
        /** @var Journey $journey */
        $journey = $request->attributes->get('journey');
        $journey->loadMissing('market');
        $policy = $policies->forMarket($journey->market);

        $input = $request->validate([
            'sort' => ['sometimes', Rule::in(JourneySort::values())],
            'corridor_width_meters' => ['sometimes', 'integer', 'between:'.$policy['corridor_min_meters'].','.$policy['corridor_max_meters']],
            'max_detour_minutes' => ['sometimes', 'integer', 'between:'.$policy['max_detour_min_minutes'].','.$policy['max_detour_max_minutes']],
            'open_at_arrival' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'cuisine' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^[a-z0-9_,\s-]*$/'],
            'feature' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^[a-z0-9_,\s-]*$/'],
            'pickup_method' => ['sometimes', 'nullable', Rule::in(array_column(PickupMethod::cases(), 'value'))],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'page.size' => ['sometimes', 'integer', 'between:1,'.$policy['max_page_size']],
        ]);

        $result = $discovery->search($journey, DiscoveryFilters::fromInput($input, $policy));

        $paginator = new LengthAwarePaginator($result->items, $result->total, $result->perPage, $result->page, ['path' => $request->url(), 'pageName' => 'page[number]']);
        $carried = $request->query();
        unset($carried['page']);
        $paginator->appends($carried)->appends('page[size]', (string) $result->perPage);

        return JourneyRestaurantResource::collection($paginator)->additional(['meta' => $result->meta]);
    }
}
