<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /customer/devices/current. The customer is the authenticated principal: no customer id is accepted, and nothing
 * about the delivery state can be set from here. The push token is bounded (FCM tokens are a few hundred characters).
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'platform' => ['required', 'string', 'in:ANDROID,android'],
            'push_provider' => ['required', 'string', 'in:FCM,FAKE,fcm,fake'],
            'push_token' => ['required', 'string', 'min:8', 'max:4096', 'regex:/^[\x21-\x7E]+$/'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'device_locale' => ['sometimes', 'nullable', 'string', 'max:16'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notifications_permission_state' => ['sometimes', 'nullable', 'string', 'in:UNKNOWN,GRANTED,DENIED,unknown,granted,denied'],
            'customer_id' => ['prohibited'],
            'customer' => ['prohibited'],
            'revoked_at' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
