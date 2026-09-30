<?php

namespace App\Models;

use App\Auth\Principal;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StaffAccount;
use App\Models\Concerns\StoresUtcTimestamps;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * FoodOnTheGo internal administrator. There is no public way to create one: accounts come from the
 * `admin:create` command or, later, an administrator's invitation. Holds nothing without role assignments.
 */
#[Fillable(['name'])]
#[Hidden(['password', 'mfa_secret', 'mfa_recovery_codes'])]
class AdminUser extends Authenticatable implements Principal
{
    /** @use HasFactory<AdminUserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, StaffAccount, StoresUtcTimestamps;

    public function principalType(): PrincipalType
    {
        return PrincipalType::AdminUser;
    }
}
