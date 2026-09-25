<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Identity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'identity' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('identity')) {
                return;
            }
            $resolved = Identity::resolve((string) $this->input('identity'));
            if ($resolved === null) {
                $validator->errors()->add('identity', 'Enter a valid email address or phone number.');

                return;
            }
            if (User::query()->where($resolved['column'], $resolved['value'])->exists()) {
                $validator->errors()->add('identity', 'An account already exists for this '.$resolved['column'].'.');
            }
        });
    }

    /**
     * @return array{column: 'email'|'phone', value: string}
     */
    public function resolvedIdentity(): array
    {
        /** @var array{column: 'email'|'phone', value: string} $resolved */
        $resolved = Identity::resolve((string) $this->input('identity'));

        return $resolved;
    }
}
