<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the listed fields exist for the client. Status, phone, verification and market are not writable
 * here — sending them changes nothing.
 */
class UpdateCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'preferred_locale' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'accept_terms' => ['sometimes', 'accepted'],
        ];
    }
}
