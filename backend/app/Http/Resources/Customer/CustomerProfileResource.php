<?php

namespace App\Http\Resources\Customer;

use App\Models\Cuisine;
use App\Models\Customer;
use App\Services\Customer\CustomerAvatarService;
use App\Services\Customer\CustomerProfileService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer's own profile (Module 25). Only the owner ever receives it, and only what they may read:
 * never internal ids, tokens, challenge data, security internals or administrator notes.
 *
 * @mixin Customer
 */
class CustomerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;
        $customer->loadMissing('market');
        $codes = is_array($customer->favorite_cuisines) ? $customer->favorite_cuisines : [];
        $names = $codes === [] ? collect() : Cuisine::query()->whereIn('code', $codes)->pluck('name', 'code');

        $locales = app(CustomerProfileService::class)->localeOptions($customer);

        return [
            'id' => $customer->public_id,
            'name' => $customer->name,
            'display_name' => $customer->displayName(),
            'phone' => $customer->phone_e164,
            'phone_masked' => PhoneNumber::mask($customer->phone_e164, $customer->market?->phone_country_code),
            'phone_verified' => $customer->phone_verified_at !== null,
            'phone_editable' => false,
            'email' => $customer->email,
            // No e-mail verification exists yet: an address is never claimed verified.
            'email_verified' => false,
            'preferred_locale' => $customer->preferred_locale ?? $locales[0] ?? 'en-IN',
            'locale_options' => $locales,
            'market' => $customer->market?->country_code,
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'gender' => $customer->gender?->value,
            'favorite_cuisines' => array_values(array_map(fn (string $code): array => ['code' => $code, 'name' => $names[$code] ?? $code], $codes)),
            'vegetarian_only' => (bool) $customer->vegetarian_only,
            'search_radius_km' => $customer->search_radius_km,
            'avatar' => $customer->avatar_path === null ? null : ['url' => CustomerAvatarService::url($customer), 'updated_at' => $customer->avatar_updated_at?->toIso8601String()],
            'status' => $customer->status->value,
            'deletion_requested_at' => $customer->deletion_requested_at?->toIso8601String(),
            'member_since' => $customer->created_at?->toDateString(),
            'version' => (int) $customer->version,
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
