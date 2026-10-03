<?php

namespace App\Models;

use App\Auth\Principal;
use App\Enums\CustomerGender;
use App\Enums\CustomerStatus;
use App\Enums\PrincipalType;
use App\Exceptions\ApiException;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customer identity: a verified phone number. No password. Status, phone and verification are never
 * mass-assignable. Module 25 adds the profile and preference fields and the customer-owned relations
 * (favorites, saved locations, recent locations, payment-method references, notification preferences);
 * CustomerProfileService writes them with forceFill after validation — nothing a client sends is trusted.
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
     * The name shown to the customer and, later, to restaurant staff on an order: the free-form name the
     * customer typed (any script, any number of words), or the masked phone number when there is none yet.
     * The one place this is decided — nothing concatenates first + last names elsewhere.
     */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : PhoneNumber::mask($this->phone_e164, $this->market?->phone_country_code);
    }

    public function assertVersion(int $expected): void
    {
        if ((int) $this->version !== $expected) {
            throw ApiException::conflict('stale_update', 'Your profile was changed somewhere else. Reload it and try again.', ['current_version' => (int) $this->version]);
        }
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return HasMany<CustomerFavoriteLocation, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(CustomerFavoriteLocation::class);
    }

    /**
     * @return HasMany<CustomerSavedLocation, $this>
     */
    public function savedLocations(): HasMany
    {
        return $this->hasMany(CustomerSavedLocation::class);
    }

    /**
     * @return HasMany<CustomerRecentLocation, $this>
     */
    public function recentLocations(): HasMany
    {
        return $this->hasMany(CustomerRecentLocation::class);
    }

    /**
     * @return HasMany<CustomerPaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(CustomerPaymentMethod::class);
    }

    /**
     * @return HasMany<CustomerNotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(CustomerNotificationPreference::class);
    }

    /**
     * @return HasMany<CustomerPhoneChange, $this>
     */
    public function phoneChanges(): HasMany
    {
        return $this->hasMany(CustomerPhoneChange::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'gender' => CustomerGender::class,
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'date_of_birth' => 'date',
            'favorite_cuisines' => 'array',
            'vegetarian_only' => 'boolean',
            'search_radius_km' => 'integer',
            'avatar_updated_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'promotions_consented_at' => 'datetime',
            'promotions_withdrawn_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
