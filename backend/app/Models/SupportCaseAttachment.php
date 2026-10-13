<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private file of a case (Module 37) — FOUNDATION, switched off (config support.attachments.enabled). Stored on a
 * private disk under a name the server chose; read only through an endpoint that checks who asks, every time.
 */
class SupportCaseAttachment extends Model
{
    use HasPublicId;

    public const NOT_SCANNED = 'NOT_SCANNED';

    public const CLEAN = 'CLEAN';

    public const QUARANTINED = 'QUARANTINED';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }
}
