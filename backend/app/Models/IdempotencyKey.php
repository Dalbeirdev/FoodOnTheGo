<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['actor', 'operation', 'key', 'request_hash', 'response_status', 'response_body', 'completed_at', 'expires_at'])]
class IdempotencyKey extends Model
{
    use Prunable, StoresUtcTimestamps;

    /**
     * Expired keys are removed by `php artisan model:prune`.
     *
     * @return Builder<IdempotencyKey>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Module 39: encrypted at rest — a replayed answer may carry a pickup code, a QR secret or a payer's details.
            'response_body' => 'encrypted:array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
