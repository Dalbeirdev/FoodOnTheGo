<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal note of an administrator about a restaurant. Never part of a restaurant or customer response.
 */
class RestaurantAdminNote extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
