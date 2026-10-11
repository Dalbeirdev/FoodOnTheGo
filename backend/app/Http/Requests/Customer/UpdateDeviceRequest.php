<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'push_token' => ['sometimes', 'string', 'min:8', 'max:4096', 'regex:/^[\x21-\x7E]+$/'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'device_locale' => ['sometimes', 'nullable', 'string', 'max:16'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notifications_permission_state' => ['sometimes', 'nullable', 'string', 'in:UNKNOWN,GRANTED,DENIED,unknown,granted,denied'],
            'customer_id' => ['prohibited'],
            'installation_id' => ['prohibited'],
            'platform' => ['prohibited'],
            'revoked_at' => ['prohibited'],
        ];
    }
}
