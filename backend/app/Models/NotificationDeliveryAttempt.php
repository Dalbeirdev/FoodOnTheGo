<?php

namespace App\Models;

use App\Enums\NotificationErrorCategory;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: one provider call. Never the token, never the raw provider body — the normalised result, the provider's
 * code, a safe error text, the latency and the payload size.
 */
class NotificationDeliveryAttempt extends Model
{
    use StoresUtcTimestamps;

    public $timestamps = false;

    public const SENT = 'SENT';

    public const FAILED = 'FAILED';

    public const RETRY = 'RETRY';

    public const INVALID_DESTINATION = 'INVALID_DESTINATION';

    protected $guarded = ['*'];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'delivery_id');
    }

    protected function casts(): array
    {
        return [
            'error_category' => NotificationErrorCategory::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
