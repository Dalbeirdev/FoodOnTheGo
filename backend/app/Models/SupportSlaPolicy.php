<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INTERNAL response and resolution targets for support cases (Module 37), by market / category / priority / team.
 * An operating aid for the service desk — NOT a contractual commitment; nothing of it is shown to a requester.
 */
class SupportSlaPolicy extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    public const TEST = 'TEST';

    public const APPROVED = 'APPROVED';

    protected $guarded = ['*'];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    protected function casts(): array
    {
        return ['first_response_minutes' => 'integer', 'resolution_minutes' => 'integer', 'pause_while_waiting_for_requester' => 'boolean'];
    }
}
