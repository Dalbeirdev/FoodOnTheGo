<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\RecentLocationResource;
use App\Models\Customer;
use App\Services\Customer\RecentLocationService;
use App\Support\Geo\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Recently chosen places of the authenticated customer (Module 25): bounded, deduplicated, clearable.
 * Recording happens when the customer picks a place for a journey (the apps call it; Module 26 will).
 */
class RecentLocationController extends Controller
{
    public function __construct(private readonly RecentLocationService $recent) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return RecentLocationResource::collection($this->recent->list($customer));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate([
            'label' => ['required', 'string', 'min:1', 'max:120'],
            'place_provider' => ['sometimes', 'nullable', 'string', 'max:32'],
            ...Location::rules(),
        ]);

        return (new RecentLocationResource($this->recent->record($customer, $input)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Clears the whole list — the customer's own history, gone in one request.
     */
    public function destroy(Request $request): Response
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $this->recent->clear($customer);

        return response()->noContent();
    }
}
