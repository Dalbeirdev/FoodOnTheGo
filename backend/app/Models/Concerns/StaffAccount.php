<?php

namespace App\Models\Concerns;

use App\Enums\StaffStatus;
use App\Models\RoleAssignment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared behaviour of password-based accounts (restaurant users, admin users): hashed password, explicit
 * status, encrypted MFA material and role assignments.
 */
trait StaffAccount
{
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null && $this->mfa_secret !== null;
    }

    /**
     * @return MorphMany<RoleAssignment, $this>
     */
    public function roleAssignments(): MorphMany
    {
        return $this->morphMany(RoleAssignment::class, 'principal');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffStatus::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_enabled_at' => 'datetime',
        ];
    }
}
