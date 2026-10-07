<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a payment attempt's timeline: what happened, who or what said so, and the step it caused (Module 30).
 * Written once, never changed. The detail holds references and codes only.
 */
class PaymentAttemptEvent extends Model
{
    public const SOURCE_SYSTEM = 'SYSTEM';

    public const SOURCE_CLIENT = 'CLIENT';

    public const SOURCE_PROVIDER = 'PROVIDER';

    public const SOURCE_WEBHOOK = 'WEBHOOK';

    public const SOURCE_JOB = 'JOB';

    public const SOURCE_ADMIN = 'ADMIN';

    use StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    protected function casts(): array
    {
        return ['detail' => 'array', 'occurred_at' => 'datetime'];
    }
}
