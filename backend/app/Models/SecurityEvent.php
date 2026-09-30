<?php

namespace App\Models;

use App\Enums\SecurityEventType;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: rows are created by SecurityEventRecorder and never updated or deleted by the application.
 */
class SecurityEvent extends Model
{
    use StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => SecurityEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
