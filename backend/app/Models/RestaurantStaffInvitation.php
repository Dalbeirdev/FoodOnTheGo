<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff invitation link. Only the SHA-256 of the token is stored; the link is single use and expires.
 */
#[Hidden(['token_hash'])]
class RestaurantStaffInvitation extends Model
{
    use StoresUtcTimestamps;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
