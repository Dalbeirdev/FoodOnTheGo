<?php

namespace App\Models;

use App\Auth\Principal;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StaffAccount;
use App\Models\Concerns\StoresUtcTimestamps;
use Database\Factories\RestaurantUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Restaurant staff identity. What the person may do, and where, comes from role assignments scoped to a
 * restaurant organization or location — never from this row.
 */
#[Fillable(['name'])]
#[Hidden(['password', 'mfa_secret', 'mfa_recovery_codes'])]
class RestaurantUser extends Authenticatable implements Principal
{
    /** @use HasFactory<RestaurantUserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, StaffAccount, StoresUtcTimestamps;

    public function principalType(): PrincipalType
    {
        return PrincipalType::RestaurantUser;
    }

    /**
     * Memberships of restaurant organizations (Module 23). What the person may do comes from the role
     * assignments derived from the ACTIVE ones.
     *
     * @return HasMany<RestaurantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(RestaurantMembership::class);
    }
}
