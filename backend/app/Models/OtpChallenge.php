<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Hidden(['code_hash'])]
class OtpChallenge extends Model
{
    use HasPublicId, Prunable, StoresUtcTimestamps;

    protected $guarded = [];

    public function isOpen(): bool
    {
        return $this->verified_at === null && $this->invalidated_at === null && $this->expires_at->isFuture();
    }

    /**
     * Challenges are kept for a week after expiry for abuse investigation, then removed by model:prune.
     *
     * @return Builder<OtpChallenge>
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
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
