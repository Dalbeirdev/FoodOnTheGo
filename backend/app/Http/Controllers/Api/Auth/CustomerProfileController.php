<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateCustomerProfileRequest;
use App\Http\Resources\Auth\PrincipalResource;
use App\Models\Customer;

class CustomerProfileController extends Controller
{
    /**
     * The customer edits their own identity fields. The target is always the authenticated customer —
     * there is no id in the URL to tamper with.
     */
    public function update(UpdateCustomerProfileRequest $request): PrincipalResource
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validated();

        $customer->fill(collect($data)->only(['name', 'email', 'preferred_locale'])->all());
        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $customer->email = mb_strtolower(trim($data['email']));
        }
        if (array_key_exists('name', $data)) {
            $customer->name = trim($data['name']);
        }
        if (($data['accept_terms'] ?? false) && $customer->terms_accepted_at === null) {
            $customer->terms_accepted_at = now();
        }
        $customer->save();

        return new PrincipalResource($customer);
    }
}
