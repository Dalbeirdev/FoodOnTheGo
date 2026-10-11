<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationErrorCategory;
use App\Enums\NotificationPolicyResult;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery of a notification over one channel to one destination (a device for PUSH). The retry state lives here;
 * every provider call is one row of `attempts`.
 */
class NotificationDelivery extends Model
{
    use HasPublicId;
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(CustomerNotification::class, 'notification_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(CustomerDevice::class, 'customer_device_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class, 'delivery_id')->orderBy('attempt_number');
    }

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationDeliveryStatus::class,
            'policy_result' => NotificationPolicyResult::class,
            'last_error_category' => NotificationErrorCategory::class,
            'next_retry_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
