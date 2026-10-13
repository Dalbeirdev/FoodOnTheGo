<?php

namespace App\Models;

use App\Enums\SupportTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a case was given to, and by whom (Module 37) — one row per change, never rewritten: the assignment history.
 */
class SupportCaseAssignment extends Model
{
    public const ROUTING = 'ROUTING';

    public const ADMIN = 'ADMIN';

    public const ESCALATION = 'ESCALATION';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_by_admin_id');
    }

    protected function casts(): array
    {
        return ['team' => SupportTeam::class, 'created_at' => 'datetime'];
    }
}
