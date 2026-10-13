<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A CSV report somebody asked for (Module 38). Built by a queued job into PRIVATE storage; readable and
 * downloadable only by the person who asked, while they still hold the permission, until it expires. The stored
 * file's location never leaves the backend.
 */
class ReportExport extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const QUEUED = 'QUEUED';

    public const PROCESSING = 'PROCESSING';

    public const READY = 'READY';

    public const FAILED = 'FAILED';

    public const EXPIRED = 'EXPIRED';

    public const ADMIN = 'ADMIN';

    public const RESTAURANT = 'RESTAURANT';

    protected $guarded = ['*'];

    protected $hidden = ['storage_reference'];

    protected function casts(): array
    {
        return [
            'filters' => 'array', 'row_count' => 'integer', 'size_bytes' => 'integer', 'download_count' => 'integer',
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime', 'last_downloaded_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->status === self::EXPIRED || ($this->expires_at !== null && $this->expires_at->isPast());
    }
}
