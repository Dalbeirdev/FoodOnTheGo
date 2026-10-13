<?php

namespace App\Services\Support;

use App\Enums\RefundStatus;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseSource;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportEscalationReason;
use App\Enums\SupportLinkType;
use App\Enums\SupportRequesterType;
use App\Enums\SupportResolutionCode;
use App\Enums\SupportTeam;
use App\Events\Support\SupportCaseChanged;
use App\Events\Support\SupportCaseMessageCreated;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Models\SupportCase;
use App\Models\SupportCaseAssignment;
use App\Models\SupportCaseEscalation;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseInternalNote;
use App\Models\SupportCaseLink;
use App\Models\SupportCaseMessage;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Support cases (Module 37): opening one, talking in it, and the work of the service desk on it.
 *
 * A CASE COORDINATES — IT DECIDES NOTHING ABOUT THE DOMAIN. Nothing in this class writes to an order, a payment, a
 * refund, a settlement or a payout. A case is resolved by a statement about the case; when that statement claims a
 * fact of another domain ("the refund was completed") the fact is read from that domain and the claim is refused if
 * it is not true there.
 *
 * WHO IS ACTING is always the authenticated caller (or the system): no input names a requester, an author, an
 * assignee-by-default, a priority or a status. Every change is one transaction on the locked case — the case, its
 * message or note, its timeline line, its links — and is announced after the commit.
 */
final class SupportCaseService
{
    public function __construct(
        private readonly SupportCatalog $catalog,
        private readonly SupportCaseStateMachine $machine,
        private readonly SupportSlaService $sla,
        private readonly SupportLinkService $links,
        private readonly SupportMetrics $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    /* ------------------------------------------------------------------ opening a case */

    /**
     * @param  array{category: string, subcategory?: string|null, subject: string, message: string, source?: string|null, locale?: string|null, links?: list<array{type: string, reference: string}>}  $input
     */
    public function openForCustomer(Customer $customer, array $input): SupportCase
    {
        $actor = SupportActor::customer($customer);
        $source = SupportCaseSource::tryFrom((string) ($input['source'] ?? '')) ?? SupportCaseSource::CustomerWeb;
        if (! in_array($source, SupportCaseSource::forCustomer(), true)) {
            $source = SupportCaseSource::CustomerWeb;
        }
        // Resolved BEFORE anything is written: a record that is not the customer's own ends the request (404).
        $records = array_map(fn (array $l): array => $this->links->resolveForCustomer($customer, self::linkType($l['type']), (string) $l['reference']), $input['links'] ?? []);
        $this->belowTheCap(SupportCase::query()->where('requester_type', SupportRequesterType::Customer->value)->where('customer_id', $customer->getKey()));

        return $this->create($actor, SupportRequesterType::Customer, $input, $source, $records, [
            'customer_id' => $customer->getKey(),
            'market_id' => $customer->market_id,
            'locale' => self::locale($input['locale'] ?? $customer->preferred_locale),
        ]);
    }

    /**
     * A restaurant's case: its organization's, and — with a location — that location's only.
     *
     * @param  array{category: string, subcategory?: string|null, subject: string, message: string, locale?: string|null, links?: list<array{type: string, reference: string}>}  $input
     */
    public function openForRestaurant(RestaurantUser $user, RestaurantOrganization $organization, ?RestaurantLocation $location, array $input): SupportCase
    {
        $actor = SupportActor::restaurant($user);
        $records = array_map(fn (array $l): array => $this->links->resolveForRestaurant($user, self::linkType($l['type']), (string) $l['reference']), $input['links'] ?? []);
        foreach ($records as [$type, $record]) {
            // What is attached belongs to the restaurant the case is opened for — not to another one the member also works for.
            if ((int) $record->getAttribute('organization_id') !== (int) $organization->getKey() || ($location !== null && (int) $record->getAttribute('location_id') !== (int) $location->getKey())) {
                throw new ApiException(422, 'validation_failed', 'This record belongs to another restaurant than the one the case is for.', ['fields' => ['links' => ['Attach a record of the restaurant you chose.']]]);
            }
        }
        $this->belowTheCap(SupportCase::query()->where('requester_type', SupportRequesterType::Restaurant->value)->where('requester_restaurant_user_id', $user->getKey()));

        return $this->create($actor, SupportRequesterType::Restaurant, $input, SupportCaseSource::RestaurantDashboard, $records, [
            'requester_restaurant_user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'location_id' => $location?->getKey(),
            'market_id' => $location?->market_id ?? $organization->primary_market_id,
            'locale' => self::locale($input['locale'] ?? $location?->locale),
        ]);
    }

    /**
     * An administrator opens a case — internal work, or on something a customer or a restaurant told them another
     * way. It is NOT shown to a customer or a restaurant: there is no requester to show it to.
     *
     * @param  array{category: string, subcategory?: string|null, subject: string, note: string, market_id?: int|null, links?: list<array{type: string, reference: string}>}  $input
     */
    public function openByAdmin(AdminUser $admin, array $input): SupportCase
    {
        $actor = SupportActor::admin($admin);
        $records = array_map(fn (array $l): array => $this->links->resolveForAdmin($admin, self::linkType($l['type']), (string) $l['reference']), $input['links'] ?? []);
        $case = $this->create($actor, SupportRequesterType::Admin, ['message' => null] + $input, SupportCaseSource::Admin, $records, [
            'created_by_admin_id' => $admin->getKey(),
            'market_id' => $input['market_id'] ?? null,
        ], (string) $input['note']);
        $this->audit->record('support.case_opened', $case, $admin, ['category' => ['from' => null, 'to' => $case->category], 'case_number' => ['from' => null, 'to' => $case->case_number]], null, $case->market_id);

        return $case;
    }

    /**
     * The one writer of a new case: the case, its first message (or note), its links, its routing and its first
     * timeline line — all or nothing.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array{0: SupportLinkType, 1: Model, 2: string}>  $records
     * @param  array<string, mixed>  $columns
     */
    public function create(SupportActor $actor, SupportRequesterType $requester, array $input, SupportCaseSource $source, array $records, array $columns, ?string $note = null, ?string $systemKey = null, ?SupportCasePriority $priority = null): SupportCase
    {
        $taxonomy = $this->catalog->resolve($requester, (string) $input['category'], $input['subcategory'] ?? null);
        $subject = SupportText::clean($input['subject'] ?? null, 'subject', (int) config('support.limits.subject_max'), false);
        $message = ($input['message'] ?? null) === null ? null : SupportText::clean($input['message'], 'message', (int) config('support.limits.message_max'));
        $note = $note === null ? null : SupportText::clean($note, 'note', (int) config('support.limits.note_max'));
        $now = CarbonImmutable::now('UTC');

        $case = DB::transaction(function () use ($actor, $requester, $taxonomy, $subject, $message, $note, $source, $records, $columns, $systemKey, $priority, $now): SupportCase {
            $case = $this->insertWithNumber($columns + [
                'requester_type' => $requester->value,
                'category' => $taxonomy['category'],
                'subcategory' => $taxonomy['subcategory'],
                'priority' => ($priority ?? $taxonomy['priority'])->value,
                'status' => SupportCaseStatus::Open->value,
                'classification' => $taxonomy['classification'],
                'restricted_class' => $taxonomy['restricted_class'],
                'assigned_team' => $taxonomy['team']->value,
                'subject' => $subject,
                'source' => $source->value,
                'system_key' => $systemKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->sla->apply($case, $now);
            (new SupportCaseAssignment)->forceFill(['case_id' => $case->getKey(), 'team' => $taxonomy['team']->value, 'source' => SupportCaseAssignment::ROUTING, 'created_at' => $now])->save();
            $this->machine->line($case, SupportCaseEvent::CASE_CREATED, $actor, SupportCaseEvent::EXTERNAL, ['category' => $taxonomy['category'], 'subcategory' => $taxonomy['subcategory'], 'source' => $source->value, 'team' => $taxonomy['team']->value, 'priority' => $case->priority->value], null, SupportCaseStatus::Open, $now);
            foreach ($records as [$type, $record, $reference]) {
                $this->links->attach($case, $type, $record, $reference, $actor);
            }
            if ($message !== null) {
                $this->append($case, $actor, $message, null, $now);
            }
            if ($note !== null) {
                $this->writeNote($case, $actor, $note, null, $now);
            }
            $case->save();

            return $case;
        });
        $this->metrics->record('case_created');
        $this->metrics->record('case_created_'.strtolower($requester->value));
        Log::info('support.case_created', ['case' => (string) $case->public_id, 'case_number' => $case->case_number, 'requester' => $requester->value, 'category' => $case->category, 'source' => $source->value, 'team' => $case->assigned_team?->value, 'priority' => $case->priority->value]);
        DB::afterCommit(fn () => event(new SupportCaseChanged(SupportCaseChanged::CREATED, $case, ['actor' => $actor->type])));

        return $case;
    }

    /* ------------------------------------------------------------------ the conversation */

    /**
     * The requester writes into their own case. A case that is over is not written to: it is reopened (inside the
     * window) or a new one is opened.
     */
    public function requesterMessage(SupportCase $case, SupportActor $actor, string $body, ?string $clientMessageId = null): SupportCaseMessage
    {
        $body = SupportText::clean($body, 'message', (int) config('support.limits.message_max'));

        return DB::transaction(function () use ($case, $actor, $body, $clientMessageId): SupportCaseMessage {
            $locked = $this->lock($case);
            if (($same = $this->sameMessage($locked, self::authorType($actor), $clientMessageId)) !== null) {
                return $same;
            }
            if (! $locked->status->acceptsRequesterMessage()) {
                throw ApiException::conflict('case_not_open', 'This case is '.strtolower($locked->status->forRequester($locked->requester_type)).'. Reopen it to write again, or open a new case.', ['status' => $locked->status->forRequester($locked->requester_type)]);
            }
            $now = CarbonImmutable::now('UTC');
            $message = $this->append($locked, $actor, $body, $clientMessageId, $now);
            // The answer support was waiting for came: the case is support's again.
            $awaited = ($locked->status === SupportCaseStatus::WaitingForCustomer && $locked->requester_type === SupportRequesterType::Customer)
                || ($locked->status === SupportCaseStatus::WaitingForRestaurant && $locked->requester_type === SupportRequesterType::Restaurant);
            if ($awaited) {
                $this->machine->move($locked, $locked->assigned_admin_id === null ? SupportCaseStatus::Open : SupportCaseStatus::InProgress, $actor, [], ['reason' => 'REQUESTER_REPLIED'], $now);
            }
            $locked->save();

            return $message;
        });
    }

    /**
     * Support answers the requester — an EXTERNAL message. With $then the case waits for the requester afterwards.
     */
    public function supportMessage(SupportCase $case, AdminUser $admin, string $body, ?string $clientMessageId = null, ?SupportCaseStatus $then = null, ?int $expectedVersion = null): SupportCaseMessage
    {
        $body = SupportText::clean($body, 'message', (int) config('support.limits.message_max'));
        $actor = SupportActor::admin($admin);

        return DB::transaction(function () use ($case, $admin, $actor, $body, $clientMessageId, $then, $expectedVersion): SupportCaseMessage {
            $locked = $this->lock($case);
            if (($same = $this->sameMessage($locked, SupportCaseMessage::AUTHOR_SUPPORT, $clientMessageId)) !== null) {
                return $same;
            }
            if ($then !== null) {
                // The reply also changes the state of the case: it carries the version it read, like every other change.
                self::sameVersion($locked, $expectedVersion);
            }
            if (! in_array($locked->requester_type, [SupportRequesterType::Customer, SupportRequesterType::Restaurant], true)) {
                throw ApiException::conflict('case_has_no_requester', 'Nobody outside the service desk reads this case: write an internal note instead.');
            }
            if ($locked->status->isFinal()) {
                throw ApiException::conflict('case_not_open', 'This case is '.strtolower($locked->status->value).': reopen it before writing to the requester.', ['status' => $locked->status->value]);
            }
            $now = CarbonImmutable::now('UTC');
            $message = $this->append($locked, $actor, $body, $clientMessageId, $now);
            $this->sla->firstResponse($locked, $now);
            if ($then !== null) {
                $mine = $locked->requester_type === SupportRequesterType::Customer ? SupportCaseStatus::WaitingForCustomer : SupportCaseStatus::WaitingForRestaurant;
                if ($then !== $mine) {
                    throw new ApiException(422, 'validation_failed', 'After a reply a case can only wait for the one it was written to.', ['fields' => ['then' => ['Use '.$mine->value.' or leave it out.']]]);
                }
                $this->machine->move($locked, $then, $actor, [], ['reason' => 'REPLIED'], $now);
            }
            $locked->save();
            $this->audit->record('support.replied', $locked, $admin, ['message' => ['from' => null, 'to' => (string) $message->public_id]], null, $locked->market_id);

            return $message;
        });
    }

    /**
     * An INTERNAL note: for administrators only. It is a row of another table than the conversation and raises no
     * message event — nothing a requester receives can carry it.
     */
    public function note(SupportCase $case, AdminUser $admin, string $body, ?string $clientNoteId = null): SupportCaseInternalNote
    {
        $body = SupportText::clean($body, 'note', (int) config('support.limits.note_max'));

        return DB::transaction(function () use ($case, $admin, $body, $clientNoteId): SupportCaseInternalNote {
            $locked = $this->lock($case);
            if ($clientNoteId !== null && ($same = SupportCaseInternalNote::query()->where('case_id', $locked->getKey())->where('author_admin_id', $admin->getKey())->where('client_note_id', $clientNoteId)->first()) !== null) {
                return $same;
            }
            $note = $this->writeNote($locked, SupportActor::admin($admin), $body, $clientNoteId, CarbonImmutable::now('UTC'));
            $locked->save();

            return $note;
        });
    }

    /* ------------------------------------------------------------------ the service desk */

    /**
     * Gives the case to a queue and / or a person. Null for the person takes it back from whoever had it.
     */
    public function assign(SupportCase $case, AdminUser $admin, ?SupportTeam $team, ?AdminUser $to, bool $unassign, ?string $note, int $expectedVersion): SupportCase
    {
        return DB::transaction(function () use ($case, $admin, $team, $to, $unassign, $note, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            self::mustBeActive($locked);
            $actor = SupportActor::admin($admin);
            $now = CarbonImmutable::now('UTC');
            $fromTeam = $locked->assigned_team;
            $fromAdmin = $locked->assigned_admin_id;
            $newTeam = $team ?? $fromTeam;
            $newAdmin = $unassign ? null : ($to?->getKey() ?? ($team !== null && $team !== $fromTeam ? null : $fromAdmin));
            if ($newTeam === $fromTeam && $newAdmin === $fromAdmin) {
                return $locked;
            }
            $locked->forceFill(['assigned_team' => $newTeam?->value, 'assigned_admin_id' => $newAdmin]);
            (new SupportCaseAssignment)->forceFill(['case_id' => $locked->getKey(), 'team' => $newTeam?->value, 'admin_id' => $newAdmin, 'source' => SupportCaseAssignment::ADMIN, 'assigned_by_admin_id' => $admin->getKey(), 'note' => self::short($note), 'created_at' => $now])->save();
            $this->sla->apply($locked, $now);
            $detail = ['from_team' => $fromTeam?->value, 'to_team' => $newTeam?->value, 'to_admin' => $to?->public_id === null || $unassign ? null : (string) $to->public_id, 'unassigned' => $newAdmin === null && $fromAdmin !== null ? true : null];
            $this->machine->line($locked, $fromAdmin === null && $fromTeam === $newTeam ? SupportCaseEvent::CASE_ASSIGNED : SupportCaseEvent::CASE_REASSIGNED, $actor, SupportCaseEvent::INTERNAL, $detail, null, null, $now);
            // A person has it: ASSIGNED. Nobody has it any more: back to OPEN. Work that is under way stays as it is.
            if ($newAdmin !== null && $newAdmin !== $fromAdmin && in_array($locked->status, [SupportCaseStatus::Open, SupportCaseStatus::Reopened], true)) {
                $this->machine->move($locked, SupportCaseStatus::Assigned, $actor, [], ['reason' => 'ASSIGNED'], $now);
            } elseif ($newAdmin === null && $locked->status === SupportCaseStatus::Assigned) {
                $this->machine->move($locked, SupportCaseStatus::Open, $actor, [], ['reason' => 'UNASSIGNED'], $now);
            } else {
                $locked->forceFill(['version' => (int) $locked->version + 1]);
            }
            $locked->save();
            $this->metrics->record('assigned');
            $this->audit->record('support.case_assigned', $locked, $admin, ['team' => ['from' => $fromTeam?->value, 'to' => $newTeam?->value], 'assignee' => ['from' => $fromAdmin === null ? null : 'set', 'to' => $newAdmin === null ? null : (string) ($to?->public_id ?? 'kept')]], $note, $locked->market_id);
            DB::afterCommit(fn () => event(new SupportCaseChanged(SupportCaseChanged::ASSIGNED, $locked, ['team' => $newTeam?->value, 'assigned' => $newAdmin !== null])));

            return $locked;
        });
    }

    public function changePriority(SupportCase $case, AdminUser $admin, SupportCasePriority $priority, string $reason, int $expectedVersion): SupportCase
    {
        return DB::transaction(function () use ($case, $admin, $priority, $reason, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            self::mustBeActive($locked);
            $from = $locked->priority;
            if ($from === $priority) {
                return $locked;
            }
            $now = CarbonImmutable::now('UTC');
            $locked->forceFill(['priority' => $priority->value, 'version' => (int) $locked->version + 1]);
            $this->sla->apply($locked, $now);
            $this->machine->line($locked, SupportCaseEvent::PRIORITY_CHANGED, SupportActor::admin($admin), SupportCaseEvent::INTERNAL, ['from' => $from->value, 'to' => $priority->value], null, null, $now);
            $this->metrics->record('priority_changed');
            $this->audit->record('support.priority_changed', $locked, $admin, ['priority' => ['from' => $from->value, 'to' => $priority->value]], $reason, $locked->market_id);
            DB::afterCommit(fn () => event(new SupportCaseChanged(SupportCaseChanged::PRIORITY, $locked, ['from' => $from->value])));

            return $locked;
        });
    }

    /**
     * The working steps of the desk: start, wait for somebody, resume. Not for ending a case (resolve / close /
     * cancel) and not for reopening — each of those has its own door.
     */
    public function step(SupportCase $case, AdminUser $admin, SupportCaseStatus $to, ?string $reason, int $expectedVersion): SupportCase
    {
        if (! in_array($to, [SupportCaseStatus::InProgress, ...SupportCaseStatus::waiting()], true)) {
            throw new ApiException(422, 'validation_failed', 'This step is not a working step.', ['fields' => ['step' => ['Not a working step.']]]);
        }

        return DB::transaction(function () use ($case, $admin, $to, $reason, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            if ($locked->status === $to) {
                return $locked;
            }
            $from = $locked->status;
            $this->machine->move($locked, $to, SupportActor::admin($admin), [], ['reason' => self::short($reason, 200)]);
            $locked->save();
            $this->audit->record('support.status_changed', $locked, $admin, ['status' => ['from' => $from->value, 'to' => $to->value]], $reason, $locked->market_id);

            return $locked;
        });
    }

    /**
     * Support considers the matter settled. A statement about the case: nothing in any other domain changes — and a
     * code that names a fact of another domain is accepted only when that domain says so.
     */
    public function resolve(SupportCase $case, AdminUser $admin, SupportResolutionCode $code, string $note, int $expectedVersion): SupportCase
    {
        $note = SupportText::clean($note, 'note', 1000);
        if (in_array($code, [SupportResolutionCode::UserWithdrew, SupportResolutionCode::DuplicateCase], true)) {
            throw new ApiException(422, 'validation_failed', 'A case is withdrawn by its requester and a duplicate is cancelled (naming the case it duplicates) — neither is a resolution.', ['fields' => ['resolution_code' => ['Choose how the case was resolved.']]]);
        }

        return DB::transaction(function () use ($case, $admin, $code, $note, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            if ($locked->status === SupportCaseStatus::Resolved) {
                return $locked;
            }
            if ($code === SupportResolutionCode::RefundCompleted) {
                $refunded = Refund::query()->whereIn('id', SupportCaseLink::query()->where('case_id', $locked->getKey())->where('link_type', SupportLinkType::Refund->value)->select('refund_id'))->where('status', RefundStatus::Refunded->value)->exists();
                if (! $refunded) {
                    throw ApiException::conflict('resolution_not_supported_by_record', 'No refund linked to this case is refunded. A case cannot say so before the refund itself does.');
                }
            }
            $now = CarbonImmutable::now('UTC');
            $from = $locked->status;
            $this->closeEscalations($locked, $now);
            $this->machine->move($locked, SupportCaseStatus::Resolved, SupportActor::admin($admin), ['resolution_code' => $code->value, 'resolution_note' => $note, 'resolved_by_admin_id' => $admin->getKey()], ['resolution_code' => $code->value], $now);
            $locked->save();
            $this->metrics->record('resolved');
            $this->audit->record('support.case_resolved', $locked, $admin, ['status' => ['from' => $from->value, 'to' => SupportCaseStatus::Resolved->value], 'resolution' => ['from' => null, 'to' => $code->value]], $note, $locked->market_id);

            return $locked;
        });
    }

    /**
     * RESOLVED → CLOSED: by an administrator, by the requester confirming, or by the clock (the system).
     */
    public function close(SupportCase $case, SupportActor $actor, ?int $expectedVersion = null): SupportCase
    {
        return DB::transaction(function () use ($case, $actor, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            if ($locked->status === SupportCaseStatus::Closed) {
                return $locked;
            }
            if ($actor->isRequester() && $locked->status !== SupportCaseStatus::Resolved) {
                // Support took the case up again between the requester's screen and their tap: nothing to confirm.
                throw self::changed($locked);
            }
            $from = $locked->status;
            $this->machine->move($locked, SupportCaseStatus::Closed, $actor, [], ['reason' => $actor->isRequester() ? 'CONFIRMED_BY_REQUESTER' : null]);
            $locked->save();
            $this->metrics->record('closed');
            if ($actor->isAdmin()) {
                $this->audit->record('support.case_closed', $locked, $actor->admin, ['status' => ['from' => $from->value, 'to' => SupportCaseStatus::Closed->value]], null, $locked->market_id);
            }

            return $locked;
        });
    }

    /**
     * The requester takes their own case back while it is still being worked on.
     */
    public function withdraw(SupportCase $case, SupportActor $actor): SupportCase
    {
        return DB::transaction(function () use ($case, $actor): SupportCase {
            $locked = $this->lock($case);
            if ($locked->status === SupportCaseStatus::Cancelled) {
                return $locked;
            }
            if (! $locked->status->isActive()) {
                // Resolved or closed in the meantime: a withdrawal is for a case that is still being worked on.
                throw self::changed($locked);
            }
            $now = CarbonImmutable::now('UTC');
            $this->closeEscalations($locked, $now);
            $this->machine->move($locked, SupportCaseStatus::Cancelled, $actor, ['resolution_code' => SupportResolutionCode::UserWithdrew->value], ['resolution_code' => SupportResolutionCode::UserWithdrew->value], $now);
            $locked->save();
            $this->metrics->record('cancelled');

            return $locked;
        });
    }

    /**
     * An administrator ends a case that is not a case: a duplicate, nothing to do, something else — with a reason.
     */
    public function cancel(SupportCase $case, AdminUser $admin, SupportResolutionCode $code, string $note, ?SupportCase $duplicateOf, int $expectedVersion): SupportCase
    {
        $note = SupportText::clean($note, 'note', 1000);
        if (! $code->forCancellation() || $code === SupportResolutionCode::UserWithdrew) {
            throw new ApiException(422, 'validation_failed', 'This is not a reason to cancel a case.', ['fields' => ['resolution_code' => ['Use DUPLICATE_CASE, NO_ACTION_REQUIRED or OTHER.']]]);
        }
        if (($code === SupportResolutionCode::DuplicateCase) !== ($duplicateOf !== null)) {
            throw new ApiException(422, 'validation_failed', 'A duplicate names the case it duplicates — and only a duplicate does.', ['fields' => ['duplicate_of' => ['Required for DUPLICATE_CASE, not accepted otherwise.']]]);
        }

        return DB::transaction(function () use ($case, $admin, $code, $note, $duplicateOf, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            if ($duplicateOf !== null && (int) $duplicateOf->getKey() === (int) $locked->getKey()) {
                throw new ApiException(422, 'validation_failed', 'A case is not a duplicate of itself.', ['fields' => ['duplicate_of' => ['Name another case.']]]);
            }
            if ($locked->status === SupportCaseStatus::Cancelled) {
                // Already cancelled (by its requester, or by a colleague): nothing changes, so nothing is recorded.
                return $locked;
            }
            $now = CarbonImmutable::now('UTC');
            $from = $locked->status;
            $this->closeEscalations($locked, $now);
            $this->machine->move($locked, SupportCaseStatus::Cancelled, SupportActor::admin($admin), ['resolution_code' => $code->value, 'resolution_note' => $note, 'resolved_by_admin_id' => $admin->getKey(), 'duplicate_of_case_id' => $duplicateOf?->getKey()], ['resolution_code' => $code->value, 'duplicate_of' => $duplicateOf?->case_number], $now);
            $locked->save();
            $this->metrics->record('cancelled');
            $this->audit->record('support.case_cancelled', $locked, $admin, ['status' => ['from' => $from->value, 'to' => SupportCaseStatus::Cancelled->value], 'resolution' => ['from' => null, 'to' => $code->value]], $note, $locked->market_id);

            return $locked;
        });
    }

    /**
     * Reopening. The requester: a RESOLVED case inside the configured window, a CLOSED one inside its own (shorter)
     * window — with a message saying why. An administrator: any RESOLVED or CLOSED case, with a reason.
     */
    public function reopen(SupportCase $case, SupportActor $actor, string $reason, ?int $expectedVersion = null): SupportCase
    {
        $reason = SupportText::clean($reason, $actor->isRequester() ? 'message' : 'reason', $actor->isRequester() ? (int) config('support.limits.message_max') : (int) config('support.limits.reason_max'));

        return DB::transaction(function () use ($case, $actor, $reason, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            if ($locked->status === SupportCaseStatus::Reopened) {
                return $locked;
            }
            $now = CarbonImmutable::now('UTC');
            if ($actor->isRequester() && $locked->status->isActive()) {
                throw ApiException::conflict('case_already_open', 'This case is already open.', ['status' => $locked->status->forRequester($locked->requester_type)]);
            }
            if ($actor->isRequester() && ! self::requesterMayReopen($locked, $now)) {
                throw ApiException::conflict('case_reopen_window_over', 'This case can no longer be reopened. Please open a new case and mention '.$locked->case_number.'.', ['status' => $locked->status->forRequester($locked->requester_type)]);
            }
            $from = $locked->status;
            $this->machine->move($locked, SupportCaseStatus::Reopened, $actor, [], ['reason' => $actor->isRequester() ? 'REOPENED_BY_REQUESTER' : 'REOPENED_BY_SUPPORT'], $now);
            if ($actor->isRequester()) {
                $this->append($locked, $actor, $reason, null, $now);
            }
            $locked->save();
            $this->metrics->record('reopened');
            if ($actor->isAdmin()) {
                $this->audit->record('support.case_reopened', $locked, $actor->admin, ['status' => ['from' => $from->value, 'to' => SupportCaseStatus::Reopened->value]], $reason, $locked->market_id);
            }

            return $locked;
        });
    }

    /**
     * Asks another queue or a higher level to look. It gives the case to that queue; it performs NOTHING in the
     * domain the case is about. A security escalation also narrows who may open the case.
     */
    public function escalate(SupportCase $case, ?AdminUser $admin, SupportEscalationReason $reason, string $level, ?SupportTeam $toTeam, ?AdminUser $toAdmin, string $note, ?int $expectedVersion): SupportCase
    {
        $note = SupportText::clean($note, 'note', 1000);
        if (! in_array($level, SupportCaseEscalation::LEVELS, true)) {
            throw new ApiException(422, 'validation_failed', 'Unknown escalation level.', ['fields' => ['level' => ['Use L2 or L3.']]]);
        }

        return DB::transaction(function () use ($case, $admin, $reason, $level, $toTeam, $toAdmin, $note, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            self::mustBeActive($locked);
            $actor = $admin === null ? SupportActor::system() : SupportActor::admin($admin);
            $now = CarbonImmutable::now('UTC');
            $fromTeam = $locked->assigned_team;
            $toTeam ??= match ($reason) {
                SupportEscalationReason::Security => SupportTeam::Security,
                SupportEscalationReason::FinancialRisk => SupportTeam::Finance,
                SupportEscalationReason::ProviderIssue => SupportTeam::Payments,
                default => $fromTeam,
            };
            $this->closeEscalations($locked, $now);
            (new SupportCaseEscalation)->forceFill([
                'case_id' => $locked->getKey(), 'reason' => $reason->value, 'level' => $level, 'from_team' => $fromTeam?->value, 'to_team' => $toTeam?->value, 'to_admin_id' => $toAdmin?->getKey(),
                'note' => $note, 'escalated_by_admin_id' => $admin?->getKey(), 'status' => SupportCaseEscalation::OPEN, 'created_at' => $now,
            ])->save();
            $moved = $toTeam !== $fromTeam || $toAdmin !== null;
            $fill = ['escalation_level' => $level, 'escalated_at' => $now];
            if ($moved) {
                $fill += ['assigned_team' => $toTeam?->value, 'assigned_admin_id' => $toAdmin?->getKey()];
                (new SupportCaseAssignment)->forceFill(['case_id' => $locked->getKey(), 'team' => $toTeam?->value, 'admin_id' => $toAdmin?->getKey(), 'source' => SupportCaseAssignment::ESCALATION, 'assigned_by_admin_id' => $admin?->getKey(), 'note' => self::short($note), 'created_at' => $now])->save();
            }
            if ($reason === SupportEscalationReason::Security) {
                $fill += ['classification' => SupportCase::RESTRICTED, 'restricted_class' => SupportCase::CLASS_SECURITY];
            }
            $locked->forceFill($fill);
            $this->sla->apply($locked, $now);
            $this->machine->line($locked, SupportCaseEvent::ESCALATED, $actor, SupportCaseEvent::INTERNAL, ['reason' => $reason->value, 'level' => $level, 'from_team' => $fromTeam?->value, 'to_team' => $toTeam?->value, 'restricted' => $reason === SupportEscalationReason::Security ? SupportCase::CLASS_SECURITY : null], null, null, $now);
            if ($moved && $toAdmin === null && $locked->status === SupportCaseStatus::Assigned) {
                $this->machine->move($locked, SupportCaseStatus::Open, $actor, [], ['reason' => 'ESCALATED'], $now);
            } elseif ($toAdmin !== null && $locked->status === SupportCaseStatus::Open) {
                $this->machine->move($locked, SupportCaseStatus::Assigned, $actor, [], ['reason' => 'ESCALATED'], $now);
            } else {
                $locked->forceFill(['version' => (int) $locked->version + 1]);
            }
            $locked->save();
            $this->metrics->record('escalated');
            $this->audit->record('support.case_escalated', $locked, $admin, ['escalation' => ['from' => null, 'to' => $reason->value.' '.$level], 'team' => ['from' => $fromTeam?->value, 'to' => $toTeam?->value]], $note, $locked->market_id);
            DB::afterCommit(fn () => event(new SupportCaseChanged(SupportCaseChanged::ESCALATED, $locked, ['reason' => $reason->value, 'level' => $level, 'to_team' => $toTeam?->value])));

            return $locked;
        });
    }

    /**
     * An administrator attaches a record they may read in its own domain.
     */
    public function link(SupportCase $case, AdminUser $admin, SupportLinkType $type, string $reference): SupportCaseLink
    {
        [$type, $record, $name] = $this->links->resolveForAdmin($admin, $type, $reference);

        return DB::transaction(function () use ($case, $admin, $type, $record, $name): SupportCaseLink {
            $locked = $this->lock($case);
            $link = $this->links->attach($locked, $type, $record, $name, SupportActor::admin($admin));
            $locked->save();
            if ($link->wasRecentlyCreated) {
                $this->audit->record('support.record_linked', $locked, $admin, ['link' => ['from' => null, 'to' => $type->value.' '.$name]], null, $locked->market_id);
            }

            return $link;
        });
    }

    /**
     * The administrators' own summary of the case and the day it should be looked at again. Internal.
     */
    public function annotate(SupportCase $case, AdminUser $admin, ?string $summary, bool $setSummary, ?CarbonImmutable $nextActionAt, bool $setNextAction, ?int $expectedVersion = null): SupportCase
    {
        return DB::transaction(function () use ($case, $admin, $summary, $setSummary, $nextActionAt, $setNextAction, $expectedVersion): SupportCase {
            $locked = $this->lock($case);
            self::sameVersion($locked, $expectedVersion);
            $actor = SupportActor::admin($admin);
            if ($setSummary) {
                $locked->forceFill(['summary' => $summary === null || trim($summary) === '' ? null : SupportText::clean($summary, 'summary', (int) config('support.limits.note_max'))]);
                $this->machine->line($locked, SupportCaseEvent::SUMMARY_CHANGED, $actor, SupportCaseEvent::INTERNAL);
            }
            if ($setNextAction) {
                $locked->forceFill(['next_action_at' => $nextActionAt]);
                $this->machine->line($locked, SupportCaseEvent::FOLLOW_UP_SET, $actor, SupportCaseEvent::INTERNAL, ['next_action_at' => $nextActionAt?->toIso8601String()]);
            }
            if ($setSummary || $setNextAction) {
                // The desk's own text about the case changed: two people editing it must not overwrite each other silently.
                $locked->forceFill(['version' => (int) $locked->version + 1]);
            }
            $locked->save();

            return $locked;
        });
    }

    /** The requester opened their case: what support wrote until now counts as read. */
    public function markRead(SupportCase $case): void
    {
        if ($case->unreadForRequester()) {
            $seen = ['requester_read_message_id' => (int) $case->last_support_message_id, 'requester_read_at' => CarbonImmutable::now('UTC')];
            // Not through the model: reading a case is not a change of the case (no version, no updated_at).
            SupportCase::query()->whereKey($case->getKey())->toBase()->update($seen);
            $case->forceFill($seen)->syncOriginal();
        }
    }

    public static function requesterMayReopen(SupportCase $case, CarbonImmutable $now): bool
    {
        return match ($case->status) {
            SupportCaseStatus::Resolved => $case->resolved_at !== null && $now->lessThanOrEqualTo(CarbonImmutable::instance($case->resolved_at)->addDays((int) config('support.reopen.resolved_days'))),
            SupportCaseStatus::Closed => (int) config('support.reopen.closed_days') > 0 && $case->closed_at !== null && $now->lessThanOrEqualTo(CarbonImmutable::instance($case->closed_at)->addDays((int) config('support.reopen.closed_days'))),
            default => false,
        };
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @param  array<string, mixed>  $columns
     */
    private function insertWithNumber(array $columns): SupportCase
    {
        for ($try = 1; ; $try++) {
            $case = (new SupportCase)->forceFill($columns + ['case_number' => SupportCaseNumbers::generate()]);
            try {
                DB::transaction(fn () => $case->save()); // a savepoint: a collision leaves the transaction usable

                return $case;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5 || ! str_contains($e->getMessage(), 'support_cases_case_number_unique')) {
                    throw $e;
                }
            }
        }
    }

    private function append(SupportCase $case, SupportActor $actor, string $body, ?string $clientMessageId, CarbonImmutable $now): SupportCaseMessage
    {
        $author = self::authorType($actor);
        $message = (new SupportCaseMessage)->forceFill([
            'case_id' => $case->getKey(),
            'author_type' => $author,
            'author_customer_id' => $actor->customer?->getKey(),
            'author_restaurant_user_id' => $actor->restaurantUser?->getKey(),
            'author_admin_id' => $actor->admin?->getKey(),
            'body' => $body,
            'client_message_id' => $clientMessageId,
            'created_at' => $now,
        ]);
        $message->save();
        $first = (int) $case->message_count === 0;
        $case->forceFill([
            'message_count' => (int) $case->message_count + 1,
            'last_message_at' => $now,
            ($actor->isRequester() ? 'last_requester_message_at' : 'last_support_message_at') => $now,
        ]);
        // What the requester writes themselves they have read — and everything before it; what support writes is new to them.
        $case->forceFill($actor->isRequester() ? ['requester_read_message_id' => $message->getKey(), 'requester_read_at' => $now] : ['last_support_message_id' => $message->getKey()]);
        $type = match ($author) {
            SupportCaseMessage::AUTHOR_CUSTOMER => SupportCaseEvent::CUSTOMER_MESSAGE,
            SupportCaseMessage::AUTHOR_RESTAURANT => SupportCaseEvent::RESTAURANT_MESSAGE,
            SupportCaseMessage::AUTHOR_SUPPORT => SupportCaseEvent::SUPPORT_MESSAGE,
            default => SupportCaseEvent::SYSTEM_MESSAGE,
        };
        $this->machine->line($case, $type, $actor, SupportCaseEvent::EXTERNAL, ['message' => (string) $message->public_id], null, null, $now);
        $this->metrics->record('message_'.strtolower($author === SupportCaseMessage::AUTHOR_SYSTEM ? 'support' : $author));
        DB::afterCommit(fn () => event(new SupportCaseMessageCreated($case, $message, $first)));

        return $message;
    }

    /** A message of the system to the requester (the clock closed the case, …). The caller holds the case. */
    public function systemMessage(SupportCase $case, string $body): SupportCaseMessage
    {
        return $this->append($case, SupportActor::system(), $body, null, CarbonImmutable::now('UTC'));
    }

    private function writeNote(SupportCase $case, SupportActor $actor, string $body, ?string $clientNoteId, CarbonImmutable $now): SupportCaseInternalNote
    {
        $note = (new SupportCaseInternalNote)->forceFill(['case_id' => $case->getKey(), 'author_admin_id' => $actor->admin?->getKey(), 'body' => $body, 'client_note_id' => $clientNoteId, 'created_at' => $now]);
        $note->save();
        $this->machine->line($case, SupportCaseEvent::INTERNAL_NOTE_ADDED, $actor, SupportCaseEvent::INTERNAL, ['note' => (string) $note->public_id], null, null, $now);
        $this->metrics->record('note_added');

        return $note;
    }

    /** A note of the system (what it saw). The caller holds the case. */
    public function systemNote(SupportCase $case, string $body): SupportCaseInternalNote
    {
        return $this->writeNote($case, SupportActor::system(), SupportText::clean($body, 'note', (int) config('support.limits.note_max')), null, CarbonImmutable::now('UTC'));
    }

    private function sameMessage(SupportCase $case, string $authorType, ?string $clientMessageId): ?SupportCaseMessage
    {
        if ($clientMessageId === null) {
            return null;
        }
        $same = SupportCaseMessage::query()->where('case_id', $case->getKey())->where('author_type', $authorType)->where('client_message_id', $clientMessageId)->first();
        if ($same !== null) {
            $this->metrics->record('message_repeated');
        }

        return $same;
    }

    public function closeEscalations(SupportCase $case, CarbonImmutable $now): void
    {
        SupportCaseEscalation::query()->where('case_id', $case->getKey())->where('status', SupportCaseEscalation::OPEN)->update(['status' => SupportCaseEscalation::CLOSED, 'closed_at' => $now]);
        $case->forceFill(['escalation_level' => null, 'escalated_at' => null]);
    }

    public function lock(SupportCase $case): SupportCase
    {
        return SupportCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
    }

    private function belowTheCap(Builder $own): void
    {
        $cap = (int) config('support.limits.open_cases_per_requester');
        if ($cap > 0 && $own->whereNotIn('status', [SupportCaseStatus::Closed->value, SupportCaseStatus::Cancelled->value, SupportCaseStatus::Resolved->value])->count() >= $cap) {
            throw new ApiException(429, 'too_many_open_cases', 'You already have '.$cap.' cases that are being worked on. Please add to one of them instead of opening another.');
        }
    }

    /** The case is no longer what the requester saw — said in the requester's own words, with nothing of the desk in it. */
    private static function changed(SupportCase $case): ApiException
    {
        return ApiException::conflict('case_changed', 'This case changed in the meantime. Please look at it again.', ['status' => $case->status->forRequester($case->requester_type)]);
    }

    private static function sameVersion(SupportCase $case, ?int $expected): void
    {
        if ($expected !== null && (int) $case->version !== $expected) {
            app(SupportMetrics::class)->record('version_conflict');
            throw ApiException::conflict('case_version_conflict', 'This case changed since you last saw it. Please refresh and try again.', ['current_version' => (int) $case->version, 'current_status' => $case->status->value]);
        }
    }

    private static function mustBeActive(SupportCase $case): void
    {
        if (! $case->status->isActive()) {
            throw ApiException::conflict('case_not_open', 'This case is '.strtolower($case->status->value).': reopen it first.', ['current_status' => $case->status->value, 'current_version' => (int) $case->version]);
        }
    }

    private static function authorType(SupportActor $actor): string
    {
        return match ($actor->type) {
            SupportCaseEvent::ACTOR_CUSTOMER => SupportCaseMessage::AUTHOR_CUSTOMER,
            SupportCaseEvent::ACTOR_RESTAURANT => SupportCaseMessage::AUTHOR_RESTAURANT,
            SupportCaseEvent::ACTOR_ADMIN => SupportCaseMessage::AUTHOR_SUPPORT,
            default => SupportCaseMessage::AUTHOR_SYSTEM,
        };
    }

    private static function linkType(string $value): SupportLinkType
    {
        return SupportLinkType::tryFrom(strtoupper(trim($value)))
            ?? throw new ApiException(422, 'validation_failed', 'Unknown kind of record.', ['fields' => ['links' => ['Unknown kind of record.']]]);
    }

    private static function locale(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?\z/', $value) === 1 ? $value : null;
    }

    private static function short(?string $text, int $max = 500): ?string
    {
        $text = $text === null ? '' : trim(preg_replace('/\p{Cc}+/u', ' ', $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
