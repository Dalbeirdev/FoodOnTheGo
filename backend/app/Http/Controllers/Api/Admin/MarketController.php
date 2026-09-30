<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketResource;
use App\Http\Support\ListQuery;
use App\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketController extends Controller
{
    /**
     * Every market in any status (read-only). Changing markets arrives with the Market / Geo module.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $list = new ListQuery($request, filterable: ['status', 'country_code'], sortable: ['name', 'country_code', 'status', 'created_at'], defaultSort: 'name');

        return MarketResource::collection($list->paginate(Market::query()));
    }
}
