<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a payout provider would send a location's money (Module 36) — as little as possible: the provider's token for
 * the destination, the provider's own mask of it and whether the provider verified it. No bank account number, no
 * IFSC, no UPI id and no KYC document is stored. Nothing here is compliance: restaurant onboarding with a real
 * provider does not exist yet.
 */
class RestaurantPayoutProfile extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const PENDING_VERIFICATION = 'PENDING_VERIFICATION';

    public const VERIFIED = 'VERIFIED';

    public const SUSPENDED = 'SUSPENDED';

    protected $guarded = ['*'];

    /** The token never leaves the backend in a payload. */
    protected $hidden = ['provider_account_reference'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'checked_at' => 'datetime', 'version' => 'integer'];
    }
}
