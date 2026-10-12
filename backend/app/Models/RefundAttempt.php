<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question asked of the provider about a refund (Module 35): create it, look it up, list the payment's refunds.
 * Written when the question is asked and completed once with what came back. Never removed.
 */
class RefundAttempt extends Model
{
    use StoresUtcTimestamps;

    public const OP_CREATE = 'CREATE';

    public const OP_LOOKUP = 'LOOKUP';

    public const OP_LIST = 'LIST';

    public const STARTED = 'STARTED';

    public const PROCESSED = 'PROCESSED';

    public const PENDING = 'PENDING';

    public const FAILED = 'FAILED';

    /** The provider understood the request and refused it. */
    public const REJECTED = 'REJECTED';

    /** No answer: the provider may or may not have acted. */
    public const UNKNOWN = 'UNKNOWN';

    /** The request was certainly not carried out (refused before it was read, or never sent). */
    public const NOT_SENT = 'NOT_SENT';

    public const NOT_FOUND = 'NOT_FOUND';

    public const UNAVAILABLE = 'UNAVAILABLE';

    /** The provider answered with a refund that is not this one (another payment, amount or currency). */
    public const MISMATCH = 'MISMATCH';

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
        return ['generation' => 'integer', 'latency_ms' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
