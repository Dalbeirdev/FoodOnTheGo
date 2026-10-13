<?php

namespace App\Services\Support;

use App\Enums\SupportCaseStatus;
use App\Events\Support\SupportCaseChanged;
use App\Exceptions\ApiException;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * The only place a support case changes its status, and the only writer of its timeline (Module 37).
 *
 * The caller holds the case's row lock inside a transaction. A step the state machine does not allow is refused with
 * a conflict and written to the timeline — a status is never "set". Every step bumps the version, numbers a timeline
 * line and announces itself after the commit.
 *
 * Nothing here touches an order, a payment, a refund or any money: a case's status is about the case.
 */
final class SupportCaseStateMachine
{
    public function __construct(private readonly SupportSlaService $sla, private readonly SupportMetrics $metrics) {}

    /**
     * @param  array<string, mixed>  $fill  other columns of the case that change with the step
     * @param  array<string, mixed>  $detail  references and codes for the timeline line
     */
    public function move(SupportCase $case, SupportCaseStatus $to, SupportActor $actor, array $fill = [], array $detail = [], ?CarbonImmutable $at = null): bool
    {
        $from = $case->status;
        if ($from === $to) {
            return false;
        }
        if (! $from->allows($to)) {
            $this->metrics->record('step_refused');
            throw ApiException::conflict('case_invalid_transition', 'This case is '.self::words($from).': it cannot become '.self::words($to).'.', ['current_status' => $from->value, 'current_version' => (int) $case->version]);
        }
        $now = $at ?? CarbonImmutable::now('UTC');
        $stamp = match ($to) {
            SupportCaseStatus::Resolved => ['resolved_at' => $now],
            SupportCaseStatus::Closed => ['closed_at' => $now],
            SupportCaseStatus::Cancelled => ['cancelled_at' => $now],
            SupportCaseStatus::Reopened => ['reopened_at' => $now, 'reopen_count' => (int) $case->reopen_count + 1, 'resolved_at' => null, 'closed_at' => null, 'resolution_code' => null, 'resolution_note' => null, 'resolved_by_admin_id' => null, 'duplicate_of_case_id' => null],
            default => [],
        };
        $case->forceFill($fill + $stamp);
        $this->sla->onTransition($case, $from, $to, $now);
        $case->forceFill(['status' => $to->value, 'version' => (int) $case->version + 1]);
        [$type, $name] = match ($to) {
            SupportCaseStatus::Resolved => [SupportCaseEvent::RESOLVED, SupportCaseChanged::RESOLVED],
            SupportCaseStatus::Closed => [SupportCaseEvent::CLOSED, SupportCaseChanged::CLOSED],
            SupportCaseStatus::Reopened => [SupportCaseEvent::REOPENED, SupportCaseChanged::REOPENED],
            SupportCaseStatus::Cancelled => [SupportCaseEvent::CANCELLED, SupportCaseChanged::CANCELLED],
            default => [SupportCaseEvent::STATUS_CHANGED, SupportCaseChanged::STATUS],
        };
        // The requester reads that the case moved (in their own words); who support waits for inside the platform is
        // an internal matter.
        $this->line($case, $type, $actor, SupportCaseEvent::EXTERNAL, $detail, $from, $to, $now);
        $facts = ['from' => $from->value, 'actor' => $actor->type] + array_intersect_key($detail, ['resolution_code' => 1, 'reason' => 1]);
        DB::afterCommit(fn () => event(new SupportCaseChanged($name, $case, $facts)));

        return true;
    }

    /**
     * Writes one numbered line of the timeline and saves the case (its sequence — and whatever the caller filled).
     *
     * @param  array<string, mixed>  $detail
     */
    public function line(SupportCase $case, string $type, SupportActor $actor, string $audience, array $detail = [], ?SupportCaseStatus $from = null, ?SupportCaseStatus $to = null, ?CarbonImmutable $at = null): SupportCaseEvent
    {
        $sequence = (int) $case->event_sequence + 1;
        $case->forceFill(['event_sequence' => $sequence])->save();
        $detail = array_filter($detail, fn ($v): bool => $v !== null && $v !== '' && $v !== []);
        $event = (new SupportCaseEvent)->forceFill([
            'case_id' => $case->getKey(),
            'sequence' => $sequence,
            'type' => mb_substr($type, 0, 32),
            'actor_type' => $actor->type,
            'actor_public_id' => $actor->publicId,
            'actor_admin_id' => $actor->admin?->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'audience' => $audience,
            'detail' => $detail === [] ? null : $detail,
            'request_id' => ($id = Context::get('request_id')) === null ? null : mb_substr((string) $id, 0, 64),
            'occurred_at' => $at ?? CarbonImmutable::now('UTC'),
        ]);
        $event->save();

        return $event;
    }

    private static function words(SupportCaseStatus $status): string
    {
        return strtolower(str_replace('_', ' ', $status->value));
    }
}
