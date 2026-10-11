<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/notifications/test — a development / test environment tool only (config notifications.admin_test_enabled):
 * one chosen customer, one registry type, the customer's own devices; audited. Never a free-text message.
 */
class TestNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'uuid'],
            'type' => ['required', 'string', 'max:64'],
            'device_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'title' => ['prohibited'],
            'body' => ['prohibited'],
            'token' => ['prohibited'],
            'push_token' => ['prohibited'],
        ];
    }
}
