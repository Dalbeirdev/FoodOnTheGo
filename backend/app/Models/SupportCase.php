<?php

namespace App\Models;

use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseSource;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportRequesterType;
use App\Enums\SupportSlaStatus;
use App\Enums\SupportTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support case (Module 37): the record of somebody asking for help — a customer, a restaurant, an administrator or
 * the system itself — and of the work on it. It points at orders, payments, refunds, settlements and payouts
 * (support_case_links) and never holds their state: what is true about them is read from them.
 */
class SupportCase extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const STANDARD = 'STANDARD';

    public const RESTRICTED = 'RESTRICTED';

    public const CLASS_FINANCE = 'FINANCE';

    public const CLASS_SECURITY = 'SECURITY';

    protected $guarded = ['*'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function restaurantUser(): BelongsTo
    {
        return $this->belongsTo(RestaurantUser::class, 'requester_restaurant_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_case_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SupportSlaPolicy::class, 'sla_policy_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportCaseMessage::class, 'case_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SupportCaseInternalNote::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportCaseEvent::class, 'case_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(SupportCaseLink::class, 'case_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SupportCaseAssignment::class, 'case_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(SupportCaseEscalation::class, 'case_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportCaseAttachment::class, 'case_id');
    }

    public function isRestricted(): bool
    {
        return $this->classification === self::RESTRICTED;
    }

    /** A support message the requester has not opened the case since. */
    public function unreadForRequester(): bool
    {
        return $this->last_support_message_id !== null && ($this->requester_read_message_id === null || (int) $this->requester_read_message_id < (int) $this->last_support_message_id);
    }

    protected function casts(): array
    {
        return [
            'requester_type' => SupportRequesterType::class,
            'priority' => SupportCasePriority::class,
            'status' => SupportCaseStatus::class,
            'assigned_team' => SupportTeam::class,
            'source' => SupportCaseSource::class,
            'sla_status' => SupportSlaStatus::class,
            'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_response_at' => 'datetime',
            'sla_paused_at' => 'datetime', 'sla_at_risk_at' => 'datetime', 'sla_breached_at' => 'datetime', 'next_action_at' => 'datetime',
            'last_message_at' => 'datetime', 'last_requester_message_at' => 'datetime', 'last_support_message_at' => 'datetime', 'requester_read_at' => 'datetime',
            'escalated_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'cancelled_at' => 'datetime',
            'message_count' => 'integer', 'event_sequence' => 'integer', 'version' => 'integer', 'reopen_count' => 'integer', 'sla_paused_seconds' => 'integer',
        ];
    }
}
