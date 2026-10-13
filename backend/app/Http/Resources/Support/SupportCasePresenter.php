<?php

namespace App\Http\Resources\Support;

use App\Enums\SupportRequesterType;
use App\Models\SupportCase;
use App\Models\SupportCaseAssignment;
use App\Models\SupportCaseAttachment;
use App\Models\SupportCaseEscalation;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseInternalNote;
use App\Models\SupportCaseMessage;
use App\Models\SupportSlaPolicy;
use App\Services\Support\SupportCaseService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How a support case is shown (Module 37) — one method per audience, so that nothing meant for the service desk can
 * reach a requester by reusing a shape.
 *
 * A REQUESTER (customer or restaurant) reads: the case number, what they chose, the status in six plain words, the
 * conversation, and the records they linked. They never read: internal notes, the priority, the queue or the person
 * the case is with, escalations, SLA targets, the resolution note, restricted markings, or which administrator wrote
 * a reply ("Support").
 */
final class SupportCasePresenter
{
    /* ------------------------------------------------------------------ requester */

    /**
     * @return array<string, mixed>
     */
    public function summaryForRequester(SupportCase $case): array
    {
        return [
            'id' => (string) $case->public_id,
            'case_number' => (string) $case->case_number,
            'category' => (string) $case->category,
            'subcategory' => $case->subcategory,
            'subject' => (string) $case->subject,
            'status' => $case->status->forRequester($case->requester_type),
            'unread' => $case->unreadForRequester(),
            'message_count' => (int) $case->message_count,
            'location' => $case->requester_type === SupportRequesterType::Restaurant && $case->location !== null ? ['id' => (string) $case->location->public_id, 'name' => (string) $case->location->name] : null,
            'created_at' => self::utc($case->created_at),
            'last_message_at' => self::utc($case->last_message_at),
            'resolved_at' => self::utc($case->resolved_at),
            'closed_at' => self::utc($case->closed_at ?? $case->cancelled_at),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $links  the requester's own linked records, read live
     * @param  bool  $mayWrite  whether this viewer may write in the case (a restaurant member needs the permission)
     * @return array<string, mixed>
     */
    public function forRequester(SupportCase $case, array $links, bool $mayWrite = true): array
    {
        $now = CarbonImmutable::now('UTC');
        $reopen = SupportCaseService::requesterMayReopen($case, $now);

        return $this->summaryForRequester($case) + [
            'links' => $links,
            'can' => [
                'reply' => $mayWrite && $case->status->acceptsRequesterMessage(),
                // Confirm that the matter is settled.
                'close' => $mayWrite && $case->status->value === 'RESOLVED',
                // Take the request back while it is being worked on.
                'withdraw' => $mayWrite && $case->status->isActive(),
                'reopen' => $mayWrite && $reopen,
            ],
            'reopen_until' => ! $reopen ? null : self::utc($case->status->value === 'RESOLVED'
                ? CarbonImmutable::instance($case->resolved_at)->addDays((int) config('support.reopen.resolved_days'))
                : CarbonImmutable::instance($case->closed_at)->addDays((int) config('support.reopen.closed_days'))),
            'limits' => ['message_max' => (int) config('support.limits.message_max')],
            'attachments' => ['enabled' => (bool) config('support.attachments.enabled')],
        ];
    }

    /**
     * A message as its requester reads it: who wrote it is a ROLE, never an administrator's name.
     *
     * @return array<string, mixed>
     */
    public function messageForRequester(SupportCaseMessage $message, SupportCase $case): array
    {
        $own = $message->author_type === $case->requester_type->value;

        return [
            'id' => (string) $message->public_id,
            'author' => $own ? 'YOU' : ($message->author_type === SupportCaseMessage::AUTHOR_SYSTEM ? 'SYSTEM' : 'SUPPORT'),
            // A restaurant's case is shared by its staff: who of them wrote is shown to them.
            'author_name' => $own && $message->author_type === SupportCaseMessage::AUTHOR_RESTAURANT ? $message->authorRestaurantUser?->name : null,
            'body' => (string) $message->body,
            'created_at' => self::utc($message->created_at),
        ];
    }

    /* ------------------------------------------------------------------ service desk */

    /**
     * A row of the queue. No message text: the queue never loads conversations.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(SupportCase $case): array
    {
        return [
            'id' => (string) $case->public_id,
            'case_number' => (string) $case->case_number,
            'subject' => (string) $case->subject,
            'category' => (string) $case->category,
            'subcategory' => $case->subcategory,
            'priority' => $case->priority->value,
            'status' => $case->status->value,
            'requester' => $this->requester($case, false),
            'restaurant' => $case->location !== null ? ['id' => (string) $case->location->public_id, 'name' => (string) $case->location->name] : ($case->organization !== null ? ['id' => null, 'name' => (string) $case->organization->display_name] : null),
            'market' => $case->market?->slug,
            'source' => $case->source->value,
            'assigned' => ['team' => $case->assigned_team?->value, 'admin' => $case->assignedAdmin === null ? null : ['id' => (string) $case->assignedAdmin->public_id, 'name' => (string) $case->assignedAdmin->name]],
            'classification' => (string) $case->classification,
            'restricted_class' => $case->restricted_class,
            'escalation_level' => $case->escalation_level,
            'sla' => ['status' => $case->sla_status->value, 'first_response_due_at' => $case->first_response_at === null ? self::utc($case->first_response_due_at) : null, 'resolution_due_at' => self::utc($case->resolution_due_at)],
            'message_count' => (int) $case->message_count,
            // The requester wrote last: support owes the next word.
            'awaiting_reply' => $case->status->isActive() && $case->last_requester_message_at !== null && ($case->last_support_message_at === null || $case->last_support_message_at->lt($case->last_requester_message_at)),
            'last_message_at' => self::utc($case->last_message_at),
            'created_at' => self::utc($case->created_at),
            'version' => (int) $case->version,
        ];
    }

    /**
     * @param  array<string, bool>  $may  what THIS administrator may do with the case now
     * @return array<string, mixed>
     */
    public function detailForAdmin(SupportCase $case, array $may, bool $seesCustomer): array
    {
        return [...$this->forAdmin($case),
            'requester' => $this->requester($case, $seesCustomer),
            'summary' => $case->summary,
            'locale' => $case->locale,
            'system_key' => $case->system_key,
            'resolution' => $case->resolution_code === null ? null : ['code' => (string) $case->resolution_code, 'note' => $case->resolution_note, 'by' => $case->resolvedBy?->name, 'duplicate_of' => $case->duplicateOf?->case_number],
            'sla' => [
                'status' => $case->sla_status->value,
                'policy' => $case->slaPolicy === null ? null : $this->slaPolicy($case->slaPolicy),
                'first_response_due_at' => self::utc($case->first_response_due_at),
                'first_response_at' => self::utc($case->first_response_at),
                'resolution_due_at' => self::utc($case->resolution_due_at),
                'paused_at' => self::utc($case->sla_paused_at),
                'paused_seconds' => (int) $case->sla_paused_seconds,
                'at_risk_at' => self::utc($case->sla_at_risk_at),
                'breached_at' => self::utc($case->sla_breached_at),
                // An internal target. Nothing here was promised to the requester.
                'contractual' => false,
            ],
            'next_action_at' => self::utc($case->next_action_at),
            'reopen_count' => (int) $case->reopen_count,
            'escalated_at' => self::utc($case->escalated_at),
            'first_response_at' => self::utc($case->first_response_at),
            'resolved_at' => self::utc($case->resolved_at),
            'closed_at' => self::utc($case->closed_at),
            'reopened_at' => self::utc($case->reopened_at),
            'cancelled_at' => self::utc($case->cancelled_at),
            'updated_at' => self::utc($case->updated_at),
            'assignments' => $case->relationLoaded('assignments') ? $case->assignments->sortBy('id')->values()->map(fn (SupportCaseAssignment $a): array => [
                'team' => $a->team?->value, 'admin' => $a->admin?->name, 'source' => (string) $a->source, 'by' => $a->assignedBy?->name, 'note' => $a->note, 'at' => self::utc($a->created_at),
            ])->all() : [],
            'escalations' => $case->relationLoaded('escalations') ? $case->escalations->sortBy('id')->values()->map(fn (SupportCaseEscalation $e): array => [
                'id' => (string) $e->public_id, 'reason' => $e->reason->value, 'level' => (string) $e->level, 'from_team' => $e->from_team?->value, 'to_team' => $e->to_team?->value, 'to_admin' => $e->toAdmin?->name,
                'note' => (string) $e->note, 'by' => $e->escalated_by_admin_id === null ? 'SYSTEM' : $e->escalatedBy?->name, 'status' => (string) $e->status, 'at' => self::utc($e->created_at), 'closed_at' => self::utc($e->closed_at),
            ])->all() : [],
            'attachments' => ['enabled' => (bool) config('support.attachments.enabled'), 'scanner' => (string) config('support.attachments.scanner')],
            // What a case can never do, said in the answer itself.
            'domain_authority' => ['mark_payment_verified' => 'NOT_PROVIDED', 'mark_refund_completed' => 'NOT_PROVIDED', 'edit_settlement' => 'NOT_PROVIDED', 'edit_payout' => 'NOT_PROVIDED'],
            'can' => $may,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messageForAdmin(SupportCaseMessage $message): array
    {
        return [
            'id' => (string) $message->public_id,
            'visibility' => 'EXTERNAL',
            'author_type' => (string) $message->author_type,
            'author_name' => match ($message->author_type) {
                SupportCaseMessage::AUTHOR_SUPPORT => $message->authorAdmin?->name ?? 'Support',
                SupportCaseMessage::AUTHOR_RESTAURANT => $message->authorRestaurantUser?->name,
                default => null,
            },
            'body' => (string) $message->body,
            'created_at' => self::utc($message->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function note(SupportCaseInternalNote $note): array
    {
        return [
            'id' => (string) $note->public_id,
            'visibility' => 'INTERNAL',
            'author_name' => $note->author_admin_id === null ? 'SYSTEM' : ($note->author?->name ?? 'Administrator'),
            'system' => $note->author_admin_id === null,
            'body' => (string) $note->body,
            'created_at' => self::utc($note->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function event(SupportCaseEvent $event): array
    {
        return [
            'sequence' => (int) $event->sequence,
            'type' => (string) $event->type,
            'actor_type' => (string) $event->actor_type,
            'actor_name' => $event->actor_type === SupportCaseEvent::ACTOR_ADMIN ? $event->admin?->name : null,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'audience' => (string) $event->audience,
            'detail' => $event->detail ?? (object) [],
            'occurred_at' => self::utc($event->occurred_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function slaPolicy(SupportSlaPolicy $p): array
    {
        return [
            'id' => (string) $p->public_id, 'name' => (string) $p->name, 'market' => $p->relationLoaded('market') ? $p->market?->slug : null, 'category' => $p->category, 'priority' => $p->priority, 'team' => $p->team,
            'first_response_minutes' => $p->first_response_minutes, 'resolution_minutes' => $p->resolution_minutes, 'pause_while_waiting_for_requester' => (bool) $p->pause_while_waiting_for_requester,
            'status' => (string) $p->status, 'approval' => (string) $p->approval, 'contractual' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attachment(SupportCaseAttachment $a): array
    {
        return ['id' => (string) $a->public_id, 'name' => (string) $a->display_name, 'mime_type' => (string) $a->mime_type, 'size_bytes' => (int) $a->size_bytes, 'scan_status' => (string) $a->scan_status, 'created_at' => self::utc($a->created_at)];
    }

    /**
     * Who asked. A customer's phone number is never part of a case: with the customer permission the viewer reads the
     * name and the last four digits, without it only that a customer asked.
     *
     * @return array<string, mixed>
     */
    private function requester(SupportCase $case, bool $seesCustomer): array
    {
        return match ($case->requester_type) {
            SupportRequesterType::Customer => ['type' => 'CUSTOMER', 'id' => $case->customer === null ? null : (string) $case->customer->public_id]
                + ($seesCustomer && $case->customer !== null ? ['name' => (string) ($case->customer->name ?? ''), 'phone' => '•••• '.substr((string) $case->customer->phone_e164, -4)] : ['name' => null, 'phone' => null]),
            SupportRequesterType::Restaurant => ['type' => 'RESTAURANT', 'id' => $case->restaurantUser === null ? null : (string) $case->restaurantUser->public_id, 'name' => $case->restaurantUser?->name, 'phone' => null],
            SupportRequesterType::Admin => ['type' => 'ADMIN', 'id' => $case->createdBy === null ? null : (string) $case->createdBy->public_id, 'name' => $case->createdBy?->name, 'phone' => null],
            SupportRequesterType::System => ['type' => 'SYSTEM', 'id' => null, 'name' => null, 'phone' => null],
        };
    }

    private static function utc(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->toIso8601String();
    }
}
