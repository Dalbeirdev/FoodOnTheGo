<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of a finance job (Module 36) — an integrity check, a reconciliation, a provider import, a settlement
 * generation, a sweep or a backfill — with what it was asked and what it found. The record of "when was this last
 * checked, and was it clean".
 */
class FinanceRun extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const VERIFY_LEDGER = 'VERIFY_LEDGER';

    public const RECONCILE_INTERNAL = 'RECONCILE_INTERNAL';

    public const PROVIDER_IMPORT = 'PROVIDER_IMPORT';

    public const SETTLEMENT_GENERATION = 'SETTLEMENT_GENERATION';

    public const POST_PENDING = 'POST_PENDING';

    public const BACKFILL = 'BACKFILL';

    public const BY_SCHEDULE = 'SCHEDULE';

    public const BY_COMMAND = 'COMMAND';

    public const BY_ADMIN = 'ADMIN';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean', 'parameters' => 'array', 'result' => 'array', 'problems' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
