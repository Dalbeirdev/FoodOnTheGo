<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One row = one signed-in session / device. Sanctum stores only the SHA-256 of the token; the public id is
 * what the API shows when listing or revoking sessions.
 */
class AccessToken extends PersonalAccessToken
{
    use HasPublicId;

    protected $table = 'personal_access_tokens';

    /**
     * Ability carried by a token that may use the platform. A token issued only so an account can enrol in
     * MFA carries ENROLL instead.
     */
    public const ACCESS = 'access';

    public const ENROLL = 'mfa:enroll';
}
