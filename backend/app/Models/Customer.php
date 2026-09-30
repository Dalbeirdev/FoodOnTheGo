<?php

namespace App\Models;

use App\Auth\Principal;
use App\Enums\CustomerStatus;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customer identity: a verified phone number. No password. Profile data beyond name / e-mail belongs to the
 * customer module. Status, phone and verification are never mass-assignable.
 */
#[Fillable(['name', 'email', 'preferred_locale'])]
class Customer extends Authenticatable implements Principal
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, HasPublicId, StoresUtcTimestamps;

    public function principalType(): PrincipalType
    {
        return PrincipalType::Customer;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function profileComplete(): bool
    {
        return $this->name !== null && $this->terms_accepted_at !== null;
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
