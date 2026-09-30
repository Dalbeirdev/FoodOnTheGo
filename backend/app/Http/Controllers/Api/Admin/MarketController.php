<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MarketStatus;
use App\Enums\Permission;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\AdminMarketResource;
use App\Http\Resources\Geo\MarketConfigurationResource;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Services\Market\GeographyAdminService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MarketController extends Controller
{
    use AuthorizesMarketScope;

    /**
     * Markets in any status — only the ones the administrator may view.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $visible = $this->marketsWith($request, Permission::AdminMarketsView);
        $list = new ListQuery($request, filterable: ['status', 'country_code'], sortable: ['name', 'country_code', 'status', 'created_at'], defaultSort: 'name');

        return AdminMarketResource::collection($list->paginate(Market::query()->when($visible !== null, fn ($q) => $q->whereIn('public_id', $visible))));
    }

    public function show(Market $market): AdminMarketResource
    {
        $this->authorizeMarket(Permission::AdminMarketsView, $market);

        return new AdminMarketResource($market);
    }

    /**
     * Status change (controlled transition, reason required).
     */
    public function update(Request $request, Market $market, GeographyAdminService $geography): AdminMarketResource
    {
        $this->authorizeMarket(Permission::AdminMarketsManage, $market);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(MarketStatus::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return new AdminMarketResource($geography->updateMarket($market, $input, $request->user()));
    }

    public function updateFeatures(Request $request, Market $market, GeographyAdminService $geography): AdminMarketResource
    {
        $this->authorizeMarket(Permission::AdminMarketFeaturesManage, $market);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $input['features'] = array_map(fn ($value): bool => (bool) $value, $input['features']);

        return new AdminMarketResource($geography->updateFeatures($market, $input, $request->user()));
    }

    public function configuration(Market $market, GeographyAdminService $geography): MarketConfigurationResource
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationView, $market);

        return new MarketConfigurationResource($geography->configuration($market));
    }

    public function updateConfiguration(Request $request, Market $market, GeographyAdminService $geography): MarketConfigurationResource
    {
        $this->authorizeMarket(Permission::AdminMarketConfigurationManage, $market);

        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'payment' => ['sometimes', 'array'],
            'tax' => ['sometimes', 'array'],
            'legal' => ['sometimes', 'array'],
            'address' => ['sometimes', 'array'],
            'ordering' => ['sometimes', 'array'],
            'locked_features' => ['sometimes', 'array'],
            'locked_features.*' => ['string', 'max:60'],
        ]);

        return new MarketConfigurationResource($geography->updateConfiguration($market, $input, $request->user()));
    }
}
