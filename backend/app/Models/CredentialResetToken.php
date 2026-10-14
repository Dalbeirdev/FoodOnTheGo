<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Hidden(['token_hash'])]
class CredentialResetToken extends Model
{
    use Prunable, StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function principal(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A reset token is worthless after it expired; it is kept seven days, then removed. Removed by `php artisan model:prune` (scheduled daily, Module 39).
     *
     * @return Builder<CredentialResetToken>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(7));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
