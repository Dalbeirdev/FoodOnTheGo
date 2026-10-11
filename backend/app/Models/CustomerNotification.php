<?php

namespace App\Models;

use App\Enums\NotificationActionTarget;
use App\Enums\NotificationCategory;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The logical customer notification — one per domain event, customer and type — and the in-app record the customer
 * reads. Title and body are the rendered, escaped snapshot of what the customer saw; `data` holds the allow-listed
 * template variables only (no secret, no PII beyond the restaurant's name and the order number).
 */
class CustomerNotification extends Model
{
    use HasPublicId;
    use StoresUtcTimestamps;

    public const SUBJECT_ORDER = 'ORDER';

    public const SUBJECT_PAYMENT = 'PAYMENT';

    public const SUBJECT_ACCOUNT = 'ACCOUNT';

    protected $table = 'notifications';

    protected $guarded = ['*'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'action_target' => NotificationActionTarget::class,
            'transactional' => 'boolean',
            'data' => 'array',
            'read_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
