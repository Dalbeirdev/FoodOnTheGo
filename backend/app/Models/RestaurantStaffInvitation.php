<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff invitation link. Only the SHA-256 of the token is stored; the link is single use and expires.
 */
#[Hidden(['token_hash'])]
class RestaurantStaffInvitation extends Model
{
    use Prunable, StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<RestaurantMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(RestaurantMembership::class, 'membership_id');
    }

    /**
     * An invitation link expired 30 days ago (used or not) is removed; the membership it created stays. Removed by `php artisan model:prune` (scheduled daily, Module 39).
     *
     * @return Builder<RestaurantStaffInvitation>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
