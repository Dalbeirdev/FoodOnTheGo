<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider event that arrived as a webhook with a valid signature (Module 30). One row per provider event however
 * often the provider delivers it. The raw payload is not kept — only its hash and the fields needed to reconcile.
 */
class PaymentWebhookEvent extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /** Accepted and stored; not processed yet. */
    public const RECEIVED = 'RECEIVED';

    public const PROCESSED = 'PROCESSED';

    /** A genuine event FoodOnTheGo does not act on, or one that belongs to no payment attempt here. */
    public const IGNORED = 'IGNORED';

    public const FAILED = 'FAILED';

    protected $guarded = ['*'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'processing_attempts' => 'integer',
            'deliveries' => 'integer',
            'provider_created_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
