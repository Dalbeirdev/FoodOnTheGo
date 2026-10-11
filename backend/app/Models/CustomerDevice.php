<?php

namespace App\Models;

use App\Enums\CustomerDeviceRevokeReason;
use App\Enums\PushPermissionState;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An app installation bound to a customer for push delivery. The token is encrypted at rest and never leaves the
 * server; `push_token_hash` is the only thing a lookup touches. A revoked row is history (its deliveries keep pointing
 * at it); the binding of an installation moves by revoking the old row and creating a new one.
 */
class CustomerDevice extends Model
{
    use HasPublicId;
    use StoresUtcTimestamps;

    public const PLATFORM_ANDROID = 'ANDROID';

    public const PROVIDER_FCM = 'FCM';

    public const PROVIDER_FAKE = 'FAKE';

    protected $guarded = ['*'];

    protected $hidden = ['push_token', 'push_token_hash'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'notifications_permission_state' => PushPermissionState::class,
            'revoke_reason' => CustomerDeviceRevokeReason::class,
            'last_seen_at' => 'datetime',
            'token_updated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
