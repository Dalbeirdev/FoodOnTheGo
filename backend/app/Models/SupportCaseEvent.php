<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a case's timeline (Module 37). Append-only and numbered per case. It carries references and codes —
 * never message text. EXTERNAL lines may be shown to the requester; INTERNAL lines stay in the service desk.
 */
class SupportCaseEvent extends Model
{
    public const CASE_CREATED = 'CASE_CREATED';

    public const CASE_ASSIGNED = 'CASE_ASSIGNED';

    public const CASE_REASSIGNED = 'CASE_REASSIGNED';

    public const STATUS_CHANGED = 'STATUS_CHANGED';

    public const CUSTOMER_MESSAGE = 'CUSTOMER_MESSAGE';

    public const RESTAURANT_MESSAGE = 'RESTAURANT_MESSAGE';

    public const SUPPORT_MESSAGE = 'SUPPORT_MESSAGE';

    public const SYSTEM_MESSAGE = 'SYSTEM_MESSAGE';

    public const INTERNAL_NOTE_ADDED = 'INTERNAL_NOTE_ADDED';

    public const ENTITY_LINKED = 'ENTITY_LINKED';

    public const ESCALATED = 'ESCALATED';

    public const PRIORITY_CHANGED = 'PRIORITY_CHANGED';

    public const RESOLVED = 'RESOLVED';

    public const CLOSED = 'CLOSED';

    public const REOPENED = 'REOPENED';

    public const CANCELLED = 'CANCELLED';

    public const STEP_REFUSED = 'STEP_REFUSED';

    public const RESTRICTED = 'RESTRICTED';

    public const SUMMARY_CHANGED = 'SUMMARY_CHANGED';

    public const FOLLOW_UP_SET = 'FOLLOW_UP_SET';

    public const DOMAIN_ACTION = 'DOMAIN_ACTION';

    public const SLA_AT_RISK = 'SLA_AT_RISK';

    public const SLA_BREACHED = 'SLA_BREACHED';

    public const ATTACHMENT_ADDED = 'ATTACHMENT_ADDED';

    public const ACTOR_CUSTOMER = 'CUSTOMER';

    public const ACTOR_RESTAURANT = 'RESTAURANT';

    public const ACTOR_ADMIN = 'ADMIN';

    public const ACTOR_SYSTEM = 'SYSTEM';

    public const EXTERNAL = 'EXTERNAL';

    public const INTERNAL = 'INTERNAL';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_admin_id');
    }

    protected function casts(): array
    {
        return ['detail' => 'array', 'sequence' => 'integer', 'occurred_at' => 'datetime'];
    }
}
