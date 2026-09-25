<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Support\Identity;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->safe()->only(['name', 'email', 'phone']);

        if (array_key_exists('email', $data)) {
            $email = $data['email'] === null ? null : strtolower(trim((string) $data['email']));
            if ($email !== $user->email) {
                $data['email_verified_at'] = null;
            }
            $data['email'] = $email;
        }
        if (array_key_exists('phone', $data)) {
            $phone = $data['phone'] === null || $data['phone'] === '' ? null : Identity::normalisePhone((string) $data['phone']);
            if ($phone !== $user->phone) {
                $data['phone_verified_at'] = null;
            }
            $data['phone'] = $phone;
        }

        $user->forceFill($data)->save();

        return new UserResource($user->fresh());
    }
}
