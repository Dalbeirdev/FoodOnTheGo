<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Identity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('phone') || $validator->errors()->has('phone')) {
                return;
            }
            $phone = Identity::normalisePhone((string) $this->input('phone'));
            if ($phone === null) {
                $validator->errors()->add('phone', 'Enter a valid phone number.');

                return;
            }
            $taken = User::query()->where('phone', $phone)->whereKeyNot($this->user()?->getKey())->exists();
            if ($taken) {
                $validator->errors()->add('phone', 'This phone number is already in use.');
            }
        });
    }
}
