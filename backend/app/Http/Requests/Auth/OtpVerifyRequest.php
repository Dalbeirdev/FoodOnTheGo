<?php

namespace App\Http\Requests\Auth;

class OtpVerifyRequest extends OtpRequestRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'device_name' => ['sometimes', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => 'Enter the code you received.'];
    }
}
