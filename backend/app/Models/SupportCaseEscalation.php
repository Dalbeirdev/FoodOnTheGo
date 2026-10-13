<?php

namespace App\Models;

use App\Enums\SupportEscalationReason;
use App\Enums\SupportTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for more attention on a case (Module 37): to another team or a higher level, with a reason. It is not a
 * priority, and it does nothing in the domain the case is about — a finance escalation asks finance to look; it
 * refunds and pays nothing.
 */
class SupportCaseEscalation extends Model
{
    use HasPublicId;

    public const OPEN = 'OPEN';

    public const CLOSED = 'CLOSED';

    public const LEVELS = ['L2', 'L3'];

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'escalated_by_admin_id');
    }

    public function toAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'to_admin_id');
    }

    protected function casts(): array
    {
        return ['reason' => SupportEscalationReason::class, 'from_team' => SupportTeam::class, 'to_team' => SupportTeam::class, 'created_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
