<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: rows are created by AuditRecorder and never updated or deleted by the application.
 */
class AuditEvent extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'datetime'];
    }
}
