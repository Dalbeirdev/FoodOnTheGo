<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerProfileResource;
use App\Models\Customer;
use App\Services\Customer\CustomerAvatarService;
use App\Services\Customer\CustomerProfileService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * The customer's own profile (Module 25). The target is always the authenticated customer — there is no id
 * in the URL. The phone number is read-only here: changing it is the verified phone-change workflow
 * (PhoneChangeController). Status, verification, market, roles and permissions are refused with 422.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly CustomerProfileService $profiles, private readonly CustomerAvatarService $avatars) {}

    public function show(Request $request): CustomerProfileResource
    {
        return new CustomerProfileResource($request->user());
    }

    public function update(Request $request): CustomerProfileResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        [$minRadius, $maxRadius] = config('customer.limits.search_radius_km');
        $input = $request->validate([
            'version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:'.(int) config('customer.limits.name')],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'preferred_locale' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'gender' => ['sometimes', 'nullable', Rule::in(['MALE', 'FEMALE', 'OTHER', 'male', 'female', 'other', ''])],
            'favorite_cuisines' => ['sometimes', 'array', 'max:'.(int) config('customer.limits.favorite_cuisines')],
            'favorite_cuisines.*' => ['string', 'max:60', 'distinct'],
            'vegetarian_only' => ['sometimes', 'boolean'],
            'search_radius_km' => ['sometimes', 'nullable', 'integer', 'between:'.$minRadius.','.$maxRadius],
            // Never from a client: identity, verification, standing and market are decided elsewhere.
            'phone' => ['prohibited'], 'phone_e164' => ['prohibited'], 'phone_verified_at' => ['prohibited'], 'status' => ['prohibited'],
            'market' => ['prohibited'], 'market_id' => ['prohibited'], 'roles' => ['prohibited'], 'permissions' => ['prohibited'],
            'terms_accepted_at' => ['prohibited'], 'email_verified_at' => ['prohibited'], 'deletion_requested_at' => ['prohibited'], 'id' => ['prohibited'], 'customer_id' => ['prohibited'],
        ], ['prohibited' => 'This field cannot be changed here.']);

        return new CustomerProfileResource($this->profiles->update($customer, $input, $customer));
    }

    public function storeAvatar(Request $request): CustomerProfileResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $request->validate(['image' => ['required', File::image()->max((int) (config('customer.avatar.max_bytes') / 1024))]]);

        return new CustomerProfileResource($this->avatars->replace($customer, $request->file('image'), $customer));
    }

    public function destroyAvatar(Request $request): CustomerProfileResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return new CustomerProfileResource($this->avatars->remove($customer, $customer));
    }
}
