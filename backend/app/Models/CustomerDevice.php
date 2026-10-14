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
use Illuminate\Support\Facades\DB;

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

    /**
     * Module 39: a binding made by a session (session_bound) is active only while that session exists and has not
     * expired — a revoked, signed-out or expired session no longer receives this customer's notifications.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(fn (Builder $bound) => $bound->where('session_bound', false)
            ->orWhereExists(fn ($token) => $token->selectRaw('1')->from('personal_access_tokens as t')
                ->whereColumn('t.id', 'customer_devices.access_token_id')
                ->where(fn ($valid) => $valid->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now()))));
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if (! $this->session_bound) {
            return true;
        }

        return $this->access_token_id !== null && DB::table('personal_access_tokens')->where('id', $this->access_token_id)
            ->where(fn ($valid) => $valid->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function casts(): array
    {
        return ['session_bound' => 'boolean',
            'push_token' => 'encrypted',
            'notifications_permission_state' => PushPermissionState::class,
            'revoke_reason' => CustomerDeviceRevokeReason::class,
            'last_seen_at' => 'datetime',
            'token_updated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
