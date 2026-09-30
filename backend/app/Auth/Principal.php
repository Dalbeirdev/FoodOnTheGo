<?php

namespace App\Auth;

use App\Enums\PrincipalType;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An authenticated identity: a Customer, a RestaurantUser or an AdminUser. The type comes from the table the
 * access token points at — never from anything the client sends.
 *
 * @property-read int $id
 * @property-read string $public_id
 */
interface Principal extends Authenticatable
{
    public function principalType(): PrincipalType;

    /**
     * Whether the account state allows signing in and using existing sessions.
     */
    public function canAuthenticate(): bool;
}
