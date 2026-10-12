<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a refund's timeline (Module 35). Append-only: written once, never changed or removed.
 */
class RefundEvent extends Model
{
    use StoresUtcTimestamps;

    public const SOURCE_SYSTEM = 'SYSTEM';

    public const SOURCE_ADMIN = 'ADMIN';

    public const SOURCE_JOB = 'JOB';

    public const SOURCE_WEBHOOK = 'WEBHOOK';

    public const SOURCE_CUSTOMER = 'CUSTOMER';

    public const SOURCE_RESTAURANT = 'RESTAURANT_USER';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    protected function casts(): array
    {
        return ['detail' => 'array', 'occurred_at' => 'datetime'];
    }
}
