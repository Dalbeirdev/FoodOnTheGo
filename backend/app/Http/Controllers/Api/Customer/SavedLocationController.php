<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\SavedLocationResource;
use App\Models\Customer;
use App\Models\CustomerSavedLocation;
use App\Services\Customer\SavedLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Saved journey locations of the authenticated customer (Module 25). Every `{savedLocation}` is looked up
 * inside the customer's own rows — another customer's id is a 404, never a hint.
 */
class SavedLocationController extends Controller
{
    public function __construct(private readonly SavedLocationService $locations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return SavedLocationResource::collection(CustomerSavedLocation::query()->where('customer_id', $customer->getKey())->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate($this->rules(true), ['prohibited' => 'This field cannot be sent here.']);

        return (new SavedLocationResource($this->locations->create($customer, $input, $customer)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, string $savedLocation): SavedLocationResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $location = $this->find($customer, $savedLocation);
        $input = $request->validate($this->rules(false), ['prohibited' => 'This field cannot be sent here.']);

        return new SavedLocationResource($this->locations->update($location, $input, $customer));
    }

    public function makeDefault(Request $request, string $savedLocation): SavedLocationResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return new SavedLocationResource($this->locations->makeDefault($this->find($customer, $savedLocation), $customer));
    }

    public function destroy(Request $request, string $savedLocation): Response
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $this->locations->delete($this->find($customer, $savedLocation), $customer);

        return response()->noContent();
    }

    private function find(Customer $customer, string $publicId): CustomerSavedLocation
    {
        return CustomerSavedLocation::query()->where('customer_id', $customer->getKey())->where('public_id', $publicId)->first()
            ?? throw ApiException::notFound('saved_location_not_found', 'This saved location does not exist.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(bool $create): array
    {
        $when = $create ? 'required' : 'sometimes';

        return [
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'label' => [$when, 'string', 'min:1', 'max:'.(int) config('customer.limits.label')],
            'kind' => ['sometimes', Rule::in(['HOME', 'WORK', 'OTHER'])],
            'line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'locality' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'formatted_address' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('customer.limits.address')],
            'place_provider' => ['sometimes', 'nullable', 'string', 'max:32'],
            'place_id' => ['sometimes', 'nullable', 'string', 'max:200'],
            'lat' => ['required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'is_default' => ['sometimes', 'boolean'],
            'location' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null) {
                    $fail('Send lat and lng to set the point, or null to remove it.');
                }
            }],
            'customer_id' => ['prohibited'], 'market_id' => ['prohibited'], 'city_id' => ['prohibited'], 'service_area_id' => ['prohibited'], 'id' => ['prohibited'],
        ];
    }
}
