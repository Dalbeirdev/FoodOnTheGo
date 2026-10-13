<?php

namespace App\Http\Controllers\Api\Admin;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportEscalationReason;
use App\Enums\SupportLinkType;
use App\Enums\SupportRequesterType;
use App\Enums\SupportResolutionCode;
use App\Enums\SupportSlaStatus;
use App\Enums\SupportTeam;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportCasePresenter;
use App\Http\Support\ListQuery;
use App\Http\Support\StrictInput;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\SupportCase;
use App\Models\SupportCaseEscalation;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseInternalNote;
use App\Models\SupportCaseLink;
use App\Models\SupportCaseMessage;
use App\Models\SupportSlaPolicy;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportCaseNumbers;
use App\Services\Support\SupportCaseService;
use App\Services\Support\SupportCatalog;
use App\Services\Support\SupportDomainActionService;
use App\Services\Support\SupportLinkService;
use App\Services\Support\SupportMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The service desk (Module 37): the queue of support cases and the work on them.
 *
 * SCOPE. admin.support.view for a market reaches that market's cases only; a case without a market needs the
 * permission for all markets. A RESTRICTED case (finance / security) is invisible — in lists, counts and search —
 * to anybody who does not hold the permission of its class, and answers 403 when asked for by id.
 *
 * ONE PERMISSION PER KIND OF WORK: view; manage (reply, status, priority, links, resolve, reopen, cancel); assign;
 * notes (read and write internal notes); escalate. None of them opens another domain: the panels of linked records
 * are gated by each DOMAIN's own read permission, and a shortcut to a domain action needs that domain's permission.
 *
 * NO GENERIC UPDATE. There is no PATCH of a case. Every change is its own action with a closed body; a status, a
 * requester, a customer or an author is never accepted from a request.
 */
class SupportCaseController extends Controller
{
    use AuthorizesMarketScope;

    private const WITH = ['customer', 'restaurantUser', 'createdBy', 'location', 'organization', 'market', 'assignedAdmin'];

    /** Queue name → what it selects. Counts and the list filter use the same definitions. */
    private const QUEUES = ['open', 'unassigned', 'mine', 'awaiting_reply', 'waiting_customer', 'waiting_restaurant', 'waiting_provider', 'waiting_finance', 'waiting_internal', 'escalated', 'restricted', 'sla_at_risk', 'sla_breached', 'reopened', 'resolved', 'closed'];

    public function __construct(
        private readonly SupportCaseService $cases,
        private readonly SupportCatalog $catalog,
        private readonly SupportLinkService $links,
        private readonly SupportDomainActionService $actions,
        private readonly SupportCasePresenter $presenter,
        private readonly SupportMetrics $metrics,
    ) {}

    /* ------------------------------------------------------------------ reading */

    public function catalog(Request $request): JsonResponse
    {
        $this->marketsWith($request, Permission::AdminSupportView);
        $values = fn (array $cases): array => array_map(fn ($c): string => $c->value, $cases);

        return response()->json([
            'categories' => array_map(fn (string $code, array $d): array => [
                'code' => $code, 'audiences' => array_values((array) $d['audiences']), 'team' => $d['team'], 'priority' => (string) $d['priority'], 'restricted' => $d['restricted'] ?? null,
                'subcategories' => array_map(fn (string $s, array $sd): array => ['code' => $s, 'audiences' => array_values((array) ($sd['audiences'] ?? $d['audiences'])), 'priority' => $sd['priority'] ?? null, 'restricted' => $sd['restricted'] ?? null], array_keys((array) $d['subcategories']), array_values((array) $d['subcategories'])),
            ], array_keys($this->catalog->categories()), array_values($this->catalog->categories())),
            'admin_categories' => array_map(fn (array $c): array => ['code' => $c['code'], 'subcategories' => $c['subcategories']], $this->catalog->forAudience(SupportRequesterType::Admin)),
            'statuses' => $values(SupportCaseStatus::cases()),
            'priorities' => $values(SupportCasePriority::cases()),
            'teams' => $values(SupportTeam::cases()),
            'resolution_codes' => $values(SupportResolutionCode::cases()),
            'escalation_reasons' => $values(SupportEscalationReason::cases()),
            'escalation_levels' => SupportCaseEscalation::LEVELS,
            'link_types' => $values(SupportLinkType::cases()),
            'queues' => self::QUEUES,
            'can' => ['create' => $this->holdsSomewhere($request, Permission::AdminSupportManage)],
            'domain_actions' => array_map(fn (string $a): array => ['action' => $a, 'required_permission' => SupportDomainActionService::PERMISSIONS[$a]->value], SupportDomainActionService::ACTIONS),
            'sla' => [
                'contractual' => false,
                'approval' => (string) config('support.sla.approval'),
                'policies' => SupportSlaPolicy::query()->with('market')->orderBy('id')->get()->map(fn (SupportSlaPolicy $p): array => $this->presenter->slaPolicy($p))->all(),
            ],
            'limits' => ['subject_max' => (int) config('support.limits.subject_max'), 'message_max' => (int) config('support.limits.message_max'), 'note_max' => (int) config('support.limits.note_max')],
            'attachments' => ['enabled' => (bool) config('support.attachments.enabled'), 'scanner' => (string) config('support.attachments.scanner')],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']])['q'] ?? null;
        $admin = $this->admin($request);
        $enum = fn (string $class, string $value): string => $class::tryFrom(strtoupper($value))?->value ?? '-';
        $list = new ListQuery($request,
            filterable: ['status', 'queue', 'priority', 'category', 'subcategory', 'team', 'assigned', 'market', 'requester', 'restaurant', 'customer', 'link_type', 'sla', 'classification', 'source', 'from', 'to', 'older_than_hours'],
            sortable: ['created_at', 'last_message_at', 'resolution_due_at', 'updated_at'],
            defaultSort: 'created_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', $enum(SupportCaseStatus::class, $v)),
                'queue' => fn (Builder $q, string $v) => $this->queue($q, $v, $admin),
                'priority' => fn (Builder $q, string $v) => $q->where('priority', $enum(SupportCasePriority::class, $v)),
                'category' => fn (Builder $q, string $v) => $q->where('category', strtoupper($v)),
                'subcategory' => fn (Builder $q, string $v) => $q->where('subcategory', strtoupper($v)),
                'team' => fn (Builder $q, string $v) => $q->where('assigned_team', $enum(SupportTeam::class, $v)),
                'assigned' => fn (Builder $q, string $v) => $v === 'none' ? $q->whereNull('assigned_admin_id') : $q->whereIn('assigned_admin_id', AdminUser::query()->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
                'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
                'requester' => fn (Builder $q, string $v) => $q->where('requester_type', $enum(SupportRequesterType::class, $v)),
                'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
                // By the customer's own id only — never by a name, a phone number or an e-mail address.
                'customer' => fn (Builder $q, string $v) => $q->whereIn('customer_id', DB::table('customers')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
                'link_type' => fn (Builder $q, string $v) => $q->whereIn('id', SupportCaseLink::query()->where('link_type', $enum(SupportLinkType::class, $v))->select('case_id')),
                'sla' => fn (Builder $q, string $v) => $q->where('sla_status', SupportSlaStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'classification' => fn (Builder $q, string $v) => $q->where('classification', strtoupper($v)),
                'source' => fn (Builder $q, string $v) => $q->where('source', strtoupper($v)),
                'from' => fn (Builder $q, string $v) => $q->where('created_at', '>=', self::day($v)),
                'to' => fn (Builder $q, string $v) => $q->where('created_at', '<', self::day($v)->addDay()),
                'older_than_hours' => fn (Builder $q, string $v) => $q->where('created_at', '<=', CarbonImmutable::now('UTC')->subHours(max(0, min(24 * 365, (int) $v)))),
            ]);
        $query = $this->visible($request)->with(self::WITH);
        if (is_string($search) && trim($search) !== '') {
            $this->search($query, trim($search));
        }
        if (! $request->has('sort')) {
            // The desk's order: the most urgent first, then the case that has waited longest; the id settles a tie.
            $query->orderByRaw("case priority when 'URGENT' then 1 when 'HIGH' then 2 when 'NORMAL' then 3 else 4 end");
        }

        return JsonResource::collection($list->paginate($query)->through(fn (SupportCase $c): array => $this->presenter->forAdmin($c)))->response();
    }

    /** How many cases each queue holds — of the cases this administrator may see. */
    public function queues(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $queues = [];
        foreach (self::QUEUES as $name) {
            $queues[$name] = (int) $this->queue($this->visible($request), $name, $admin)->count();
        }

        return response()->json(['queues' => $queues, 'total' => (int) $this->visible($request)->count()]);
    }

    public function show(Request $request, string $supportCase): JsonResponse
    {
        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    /** The conversation with the requester — EXTERNAL messages only. Internal notes are another resource. */
    public function messages(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase, Permission::AdminSupportView);
        $page = SupportCaseMessage::query()->with(['authorAdmin', 'authorRestaurantUser'])->where('case_id', $case->getKey())->orderBy('id')->paginate(...$this->page($request, (int) config('support.limits.messages_page_size')));

        return JsonResource::collection($page->through(fn (SupportCaseMessage $m): array => $this->presenter->messageForAdmin($m)))->response();
    }

    public function notes(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase, Permission::AdminSupportNotes);
        $page = SupportCaseInternalNote::query()->with('author')->where('case_id', $case->getKey())->orderBy('id')->paginate(...$this->page($request, (int) config('support.limits.messages_page_size')));

        return JsonResource::collection($page->through(fn (SupportCaseInternalNote $n): array => $this->presenter->note($n)))->response();
    }

    /** The timeline, in the order things happened (the sequence, not the clock). */
    public function events(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase, Permission::AdminSupportView);
        $page = SupportCaseEvent::query()->with('admin')->where('case_id', $case->getKey())->orderBy('sequence')->paginate(...$this->page($request, 100));

        return JsonResource::collection($page->through(fn (SupportCaseEvent $e): array => $this->presenter->event($e)))->response();
    }

    /** The linked records as their own domains have them NOW — each only for a viewer who may read that domain. */
    public function links(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase, Permission::AdminSupportView);

        return response()->json(['links' => $this->links->panelsForAdmin($case, $this->admin($request))]);
    }

    /** Who a case of this market can be given to: the administrators who may work cases there. */
    public function agents(Request $request): JsonResponse
    {
        $input = $request->validate(['market' => ['sometimes', 'nullable', 'string', 'max:60']]);
        // Whoever may give a case to a person — by assigning it or by escalating it to one.
        if (! $this->holdsSomewhere($request, Permission::AdminSupportAssign)) {
            $this->marketsWith($request, Permission::AdminSupportEscalate);
        }
        $market = isset($input['market']) ? (Market::query()->where('slug', $input['market'])->first() ?? throw new ApiException(422, 'validation_failed', 'Unknown market.', ['fields' => ['market' => ['Unknown market.']]])) : null;
        $caller = $this->admin($request);
        if ($market !== null && ! $this->holds($caller, Permission::AdminSupportAssign, $market) && ! $this->holds($caller, Permission::AdminSupportEscalate, $market)) {
            // The people of a market are named only to somebody who may give a case to a person in THAT market.
            throw new AuthorizationException;
        }
        $agents = AdminUser::query()->orderBy('name')->get()->filter(fn (AdminUser $a): bool => $a->canAuthenticate() && $this->holds($a, Permission::AdminSupportManage, $market))->values();

        return response()->json(['agents' => $agents->map(fn (AdminUser $a): array => ['id' => (string) $a->public_id, 'name' => (string) $a->name])->all()]);
    }

    /** Numbers of the desk, from the cases themselves — of the cases this administrator may see. */
    public function metrics(Request $request): JsonResponse
    {
        return response()->json(self::numbers($this->visible($request), fn (): Builder => $this->visible($request)));
    }

    /** The support domain in numbers, for the System page (platform-wide). */
    public function system(): JsonResponse
    {
        return response()->json([
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'cases' => self::numbers(SupportCase::query(), fn (): Builder => SupportCase::query()),
            'events' => $this->metrics->events(24),
            'window_hours' => 24,
            'policies' => [
                'priority_and_routing' => ['value' => 'BY_CATEGORY (config/support.php)', 'approval' => (string) config('support.routing.approval')],
                'reopen' => ['value' => ['resolved_days' => (int) config('support.reopen.resolved_days'), 'closed_days' => (int) config('support.reopen.closed_days')], 'approval' => (string) config('support.reopen.approval')],
                'auto_close' => ['value' => ['resolved_after_days' => config('support.auto_close.resolved_after_days'), 'no_response_after_days' => config('support.auto_close.no_response_after_days')], 'approval' => (string) config('support.auto_close.approval')],
                'sla' => ['value' => ['active_policies' => (int) SupportSlaPolicy::query()->where('status', SupportSlaPolicy::ACTIVE)->count(), 'approved_policies' => (int) SupportSlaPolicy::query()->where('status', SupportSlaPolicy::ACTIVE)->where('approval', SupportSlaPolicy::APPROVED)->count(), 'auto_escalate' => (bool) config('support.sla.auto_escalate')], 'approval' => (string) config('support.sla.approval')],
                'system_cases' => ['value' => ['payment_order_recovery' => (bool) config('support.system_cases.payment_order_recovery'), 'refund_review' => (bool) config('support.system_cases.refund_review'), 'finance_difference_min_severity' => config('support.system_cases.finance_difference_min_severity'), 'dispute' => (bool) config('support.system_cases.dispute'), 'security' => 'READINESS_ONLY'], 'approval' => (string) config('support.system_cases.approval')],
                'retention' => ['value' => 'NOTHING_IS_DELETED', 'approval' => (string) config('support.retention.policy')],
            ],
            'flags' => [
                'contractual_sla' => false,
                'attachments' => (bool) config('support.attachments.enabled') ? 'ENABLED' : 'DISABLED',
                'attachment_scanner' => (string) config('support.attachments.scanner') === 'none' ? 'NOT_CONFIGURED' : (string) config('support.attachments.scanner'),
                'restaurant_notifications' => 'UNREAD_MARK_ONLY',
                'admin_notifications' => 'NOT_IMPLEMENTED',
                'security_admin_role' => 'NOT_DEFINED',
                'support_marks_payment_verified' => false,
                'support_marks_refund_completed' => false,
                'support_edits_settlement_or_payout' => false,
                'realtime' => 'POLLING',
                'production' => 'NO',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ opening a case */

    /**
     * An administrator opens a case: internal work, or something a customer or a restaurant reported another way.
     * It has no requester — nobody outside the service desk reads it.
     */
    public function store(Request $request): JsonResponse
    {
        StrictInput::only($request, ['category', 'subcategory', 'subject', 'note', 'market', 'links']);
        $input = $request->validate([
            'category' => ['required', 'string', 'max:24'],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:1000'],
            'note' => ['required', 'string', 'max:20000'],
            'market' => ['sometimes', 'nullable', 'string', 'max:60'],
            'links' => ['sometimes', 'array', 'max:'.(int) config('support.limits.links_per_case')],
            'links.*' => ['array:type,reference'],
            'links.*.type' => ['required', 'string', Rule::in(array_map(fn (SupportLinkType $t): string => $t->value, SupportLinkType::cases()))],
            'links.*.reference' => ['required', 'string', 'max:80'],
        ]);
        $market = isset($input['market']) ? (Market::query()->where('slug', $input['market'])->first() ?? throw new ApiException(422, 'validation_failed', 'Unknown market.', ['fields' => ['market' => ['Unknown market.']]])) : null;
        $this->authorizeFor(Permission::AdminSupportManage, $market);
        $case = $this->cases->openByAdmin($this->admin($request), ['market_id' => $market?->getKey(), 'links' => array_values($input['links'] ?? [])] + $input);
        // A case of a restricted category (security, dispute, a reconciliation difference) may be out of its own author's
        // sight the moment it exists: they are told that it was opened and where it went — not refused after the fact.
        $made = SupportCase::query()->with(self::WITH)->whereKey($case->getKey())->firstOrFail();
        if (! $this->mayOpen($request, $made)) {
            return response()->json(['case' => self::outOfSight($made)], 201);
        }

        return $this->answer($request, $this->find($request, (string) $case->public_id, Permission::AdminSupportView), 201);
    }

    /* ------------------------------------------------------------------ conversation and notes */

    /** An EXTERNAL message: the requester reads it. */
    public function reply(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['message', 'visibility', 'then', 'client_message_id', 'expected_version']);
        $input = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            // Said out loud by the client so that a reply is never sent through a composer that was showing "internal".
            'visibility' => ['required', Rule::in(['EXTERNAL'])],
            'then' => ['sometimes', 'nullable', Rule::in([SupportCaseStatus::WaitingForCustomer->value, SupportCaseStatus::WaitingForRestaurant->value])],
            'client_message_id' => ['sometimes', 'nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            // A reply that also moves the case (then) is a change of state: it carries the version that was read.
            'expected_version' => ['required_with:then', 'nullable', 'integer', 'min:1'],
        ], ['visibility.in' => 'A reply is EXTERNAL. An internal note is sent to the notes of the case.', 'expected_version.required_with' => 'A reply that makes the case wait needs the version of the case you read.']);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $message = $this->cases->supportMessage($case, $this->admin($request), (string) $input['message'], $input['client_message_id'] ?? null, isset($input['then']) ? SupportCaseStatus::from($input['then']) : null, isset($input['then'], $input['expected_version']) ? (int) $input['expected_version'] : null);

        return response()->json(['message' => $this->presenter->messageForAdmin($message->load(['authorAdmin', 'authorRestaurantUser'])), 'case' => $this->detail($request, $this->find($request, $supportCase, Permission::AdminSupportView))], 201);
    }

    /** An INTERNAL note: administrators only. */
    public function note(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['note', 'visibility', 'client_note_id']);
        $input = $request->validate([
            'note' => ['required', 'string', 'max:20000'],
            'visibility' => ['required', Rule::in(['INTERNAL'])],
            'client_note_id' => ['sometimes', 'nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], ['visibility.in' => 'A note is INTERNAL. A message to the requester is sent as a reply.']);
        $case = $this->find($request, $supportCase, Permission::AdminSupportNotes);
        $note = $this->cases->note($case, $this->admin($request), (string) $input['note'], $input['client_note_id'] ?? null);

        return response()->json(['note' => $this->presenter->note($note->load('author'))], 201);
    }

    /* ------------------------------------------------------------------ the work */

    public function assign(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['team', 'admin', 'unassign', 'note', 'expected_version']);
        $input = $request->validate([
            'team' => ['sometimes', 'nullable', Rule::in(array_map(fn (SupportTeam $t): string => $t->value, SupportTeam::cases()))],
            'admin' => ['sometimes', 'nullable', 'string', 'uuid'],
            'unassign' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportAssign);
        $to = $this->assignee($case, $input['admin'] ?? null);
        if (! isset($input['team']) && $to === null && ! ($input['unassign'] ?? false)) {
            throw new ApiException(422, 'validation_failed', 'Name a queue, a person, or ask to take the case back from its person.', ['fields' => ['team' => ['A queue, a person or unassign is required.']]]);
        }
        $this->cases->assign($case, $this->admin($request), isset($input['team']) ? SupportTeam::from($input['team']) : null, $to, (bool) ($input['unassign'] ?? false), $input['note'] ?? null, (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    public function priority(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['priority', 'reason', 'expected_version']);
        $input = $request->validate([
            'priority' => ['required', Rule::in(array_map(fn (SupportCasePriority $p): string => $p->value, SupportCasePriority::cases()))],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->changePriority($case, $this->admin($request), SupportCasePriority::from($input['priority']), (string) $input['reason'], (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    public function start(Request $request, string $supportCase): JsonResponse
    {
        return $this->step($request, $supportCase, SupportCaseStatus::InProgress);
    }

    public function resume(Request $request, string $supportCase): JsonResponse
    {
        return $this->step($request, $supportCase, SupportCaseStatus::InProgress);
    }

    /** The case waits for somebody: the customer, the restaurant, the provider, finance or an internal team. */
    public function wait(Request $request, string $supportCase): JsonResponse
    {
        $for = $request->validate(['for' => ['required', Rule::in(['CUSTOMER', 'RESTAURANT', 'PROVIDER', 'FINANCE', 'INTERNAL'])]])['for'];

        return $this->step($request, $supportCase, SupportCaseStatus::from('WAITING_FOR_'.$for), ['for']);
    }

    public function resolve(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['resolution_code', 'note', 'expected_version']);
        $input = $request->validate([
            'resolution_code' => ['required', Rule::in(array_map(fn (SupportResolutionCode $c): string => $c->value, SupportResolutionCode::cases()))],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->resolve($case, $this->admin($request), SupportResolutionCode::from($input['resolution_code']), (string) $input['note'], (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    public function close(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['expected_version']);
        $input = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->close($case, SupportActor::admin($this->admin($request)), (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    public function reopen(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['reason', 'expected_version']);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'expected_version' => ['required', 'integer', 'min:1']]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->reopen($case, SupportActor::admin($this->admin($request)), (string) $input['reason'], (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    public function cancel(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['resolution_code', 'note', 'duplicate_of', 'expected_version']);
        $input = $request->validate([
            'resolution_code' => ['required', Rule::in(array_map(fn (SupportResolutionCode $c): string => $c->value, SupportResolutionCode::cases()))],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'duplicate_of' => ['sometimes', 'nullable', 'string', 'max:40'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        // The case it duplicates must be one this administrator may read: a number alone opens nothing.
        $original = isset($input['duplicate_of']) && trim((string) $input['duplicate_of']) !== '' ? $this->find($request, trim((string) $input['duplicate_of']), Permission::AdminSupportView) : null;
        $this->cases->cancel($case, $this->admin($request), SupportResolutionCode::from($input['resolution_code']), (string) $input['note'], $original, (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    public function escalate(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['reason', 'level', 'team', 'admin', 'note', 'expected_version']);
        $input = $request->validate([
            'reason' => ['required', Rule::in(array_map(fn (SupportEscalationReason $r): string => $r->value, SupportEscalationReason::cases()))],
            'level' => ['required', Rule::in(SupportCaseEscalation::LEVELS)],
            'team' => ['sometimes', 'nullable', Rule::in(array_map(fn (SupportTeam $t): string => $t->value, SupportTeam::cases()))],
            'admin' => ['sometimes', 'nullable', 'string', 'uuid'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportEscalate);
        $this->cases->escalate($case, $this->admin($request), SupportEscalationReason::from($input['reason']), (string) $input['level'], isset($input['team']) ? SupportTeam::from($input['team']) : null, $this->assignee($case, $input['admin'] ?? null, $input['reason'] === SupportEscalationReason::Security->value || ($input['team'] ?? null) === SupportTeam::Security->value ? SupportCase::CLASS_SECURITY : null), (string) $input['note'], (int) $input['expected_version']);
        // A security escalation may take the case out of the escalating person's own sight: answer what they may still see.
        $after = SupportCase::query()->whereKey($case->getKey())->firstOrFail();
        if (! $this->mayOpen($request, $after)) {
            return response()->json(['case' => self::outOfSight($after)]);
        }

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    /** Attaches a record the administrator may read in its own domain. */
    public function link(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['type', 'reference']);
        $input = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (SupportLinkType $t): string => $t->value, SupportLinkType::cases()))],
            'reference' => ['required', 'string', 'max:80'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->link($case, $this->admin($request), SupportLinkType::from($input['type']), (string) $input['reference']);

        return response()->json(['links' => $this->links->panelsForAdmin($this->find($request, $supportCase, Permission::AdminSupportView), $this->admin($request))], 201);
    }

    /** The administrators' own summary and follow-up day. */
    public function annotate(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['summary', 'next_action_at', 'expected_version']);
        $input = $request->validate(['summary' => ['sometimes', 'nullable', 'string', 'max:20000'], 'next_action_at' => ['sometimes', 'nullable', 'date'], 'expected_version' => ['required', 'integer', 'min:1']]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->annotate($case, $this->admin($request), $input['summary'] ?? null, array_key_exists('summary', $input), isset($input['next_action_at']) ? CarbonImmutable::parse($input['next_action_at'])->utc() : null, array_key_exists('next_action_at', $input), (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    /**
     * A shortcut to an action of ANOTHER domain on a linked record. The domain's own service runs it and the
     * domain's own permission decides — holding a support permission is never enough.
     */
    public function domainAction(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['action', 'link', 'reason']);
        $input = $request->validate([
            'action' => ['required', Rule::in(SupportDomainActionService::ACTIONS)],
            'link' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $link = SupportCaseLink::query()->where('case_id', $case->getKey())->where('public_id', $input['link'])->first() ?? throw ApiException::notFound('case_link_not_found', 'This record is not linked to this case.');
        $result = $this->actions->run($case, $this->admin($request), (string) $input['action'], $link, (string) $input['reason']);

        return response()->json(['result' => $result, 'links' => $this->links->panelsForAdmin($this->find($request, $supportCase, Permission::AdminSupportView), $this->admin($request))]);
    }

    /* ------------------------------------------------------------------ internals */

    /** Whether the caller holds this permission in any market at all (a question, not a gate). */
    private function holdsSomewhere(Request $request, Permission $permission): bool
    {
        try {
            $this->marketsWith($request, $permission);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function step(Request $request, string $supportCase, SupportCaseStatus $to, array $more = []): JsonResponse
    {
        StrictInput::only($request, ['reason', 'expected_version', ...$more]);
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'expected_version' => ['required', 'integer', 'min:1']]);
        $case = $this->find($request, $supportCase, Permission::AdminSupportManage);
        $this->cases->step($case, $this->admin($request), $to, $input['reason'] ?? null, (int) $input['expected_version']);

        return $this->answer($request, $this->find($request, $supportCase, Permission::AdminSupportView));
    }

    /**
     * What somebody is told about a case they have just put out of their own sight.
     *
     * @return array<string, mixed>
     */
    private static function outOfSight(SupportCase $case): array
    {
        return ['id' => (string) $case->public_id, 'case_number' => (string) $case->case_number, 'classification' => (string) $case->classification, 'restricted_class' => $case->restricted_class, 'visible' => false];
    }

    private function answer(Request $request, SupportCase $case, int $status = 200): JsonResponse
    {
        return response()->json(['case' => $this->detail($request, $case)], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, SupportCase $case): array
    {
        $case->loadMissing([...self::WITH, 'resolvedBy', 'duplicateOf', 'slaPolicy.market', 'assignments.admin', 'assignments.assignedBy', 'escalations.escalatedBy', 'escalations.toAdmin']);
        $admin = $this->admin($request);
        $may = fn (Permission $p): bool => $this->holds($admin, $p, $case->market);
        $manage = $may(Permission::AdminSupportManage);
        $active = $case->status->isActive();
        $external = in_array($case->requester_type, [SupportRequesterType::Customer, SupportRequesterType::Restaurant], true);

        return $this->presenter->detailForAdmin($case, [
            'reply' => $manage && $external && ! $case->status->isFinal(),
            'notes' => $may(Permission::AdminSupportNotes),
            'assign' => $may(Permission::AdminSupportAssign) && $active,
            'priority' => $manage && $active,
            'start' => $manage && $case->status->allows(SupportCaseStatus::InProgress),
            // The same step from a waiting state, named for what it is on the screen.
            'resume' => $manage && $case->status->isWaiting() && $case->status->allows(SupportCaseStatus::InProgress),
            'wait' => $manage && $active,
            'resolve' => $manage && $active,
            'close' => $manage && $case->status === SupportCaseStatus::Resolved,
            'reopen' => $manage && in_array($case->status, [SupportCaseStatus::Resolved, SupportCaseStatus::Closed], true),
            'cancel' => $manage && $active,
            'escalate' => $may(Permission::AdminSupportEscalate) && $active,
            'link' => $manage,
            'annotate' => $manage,
        ], $may(Permission::AdminCustomersView));
    }

    /**
     * The cases the caller may see: their markets, and of restricted cases only the classes they hold.
     *
     * @return Builder<SupportCase>
     */
    private function visible(Request $request): Builder
    {
        $markets = $this->marketKeys($request, Permission::AdminSupportView);
        $finance = $this->optionalMarketKeys($request, Permission::AdminSupportRestrictedFinance);
        $security = $this->optionalMarketKeys($request, Permission::AdminSupportRestrictedSecurity);
        $class = function (Builder $q, string $name, array|false|null $keys): void {
            if ($keys === false) {
                return;
            }
            $q->orWhere(fn (Builder $r) => $r->where('restricted_class', $name)->when($keys !== null, fn (Builder $m) => $m->whereIn('market_id', $keys)));
        };

        return SupportCase::query()
            ->when($markets !== null, fn (Builder $q) => $q->whereIn('market_id', $markets))
            ->where(function (Builder $q) use ($class, $finance, $security): void {
                $q->where('classification', SupportCase::STANDARD);
                $class($q, SupportCase::CLASS_FINANCE, $finance);
                $class($q, SupportCase::CLASS_SECURITY, $security);
            });
    }

    private function find(Request $request, string $reference, Permission $permission): SupportCase
    {
        $case = SupportCase::query()->with(self::WITH)
            ->where(fn ($q) => Str::isUuid($reference) ? $q->where('public_id', $reference) : $q->where('case_number', SupportCaseNumbers::looksLikeOne($reference) ? strtoupper($reference) : '-'))
            ->first() ?? throw ApiException::notFound('case_not_found', 'This case does not exist.');
        $this->authorizeFor($permission, $case->market);
        if (! $this->mayOpen($request, $case)) {
            $this->metrics->record('restricted_refused');
            throw new ApiException(403, 'case_restricted', 'This case is restricted. It needs a permission you do not hold.', ['restricted_class' => $case->restricted_class]);
        }

        return $case;
    }

    /** A restricted case opens only for a holder of its class's permission. */
    private function mayOpen(Request $request, SupportCase $case): bool
    {
        if (! $case->isRestricted()) {
            return true;
        }

        return $this->holds($this->admin($request), $case->restricted_class === SupportCase::CLASS_SECURITY ? Permission::AdminSupportRestrictedSecurity : Permission::AdminSupportRestrictedFinance, $case->market);
    }

    /** The person a case is given to must be able to open it. */
    private function assignee(SupportCase $case, ?string $publicId, ?string $becomes = null): ?AdminUser
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        $to = AdminUser::query()->where('public_id', $publicId)->first();
        // $becomes: the class the case will have after this action (a security escalation restricts it) — the person
        // must be able to open the case as it will be, not as it is.
        $class = $becomes ?? ($case->isRestricted() ? $case->restricted_class : null);
        $can = $to !== null && $to->canAuthenticate() && $this->holds($to, Permission::AdminSupportManage, $case->market)
            && ($class === null || $this->holds($to, $class === SupportCase::CLASS_SECURITY ? Permission::AdminSupportRestrictedSecurity : Permission::AdminSupportRestrictedFinance, $case->market));
        if (! $can) {
            throw new ApiException(422, 'validation_failed', 'This person cannot work this case.', ['fields' => ['admin' => ['Choose an administrator who may work cases of this market'.($class !== null ? ' and open restricted cases of this class' : '').'.']]]);
        }

        return $to;
    }

    private function queue(Builder $query, string $name, AdminUser $admin): Builder
    {
        $active = SupportCaseStatus::activeValues();

        return match (strtolower($name)) {
            'open' => $query->whereIn('status', $active),
            'unassigned' => $query->whereIn('status', $active)->whereNull('assigned_admin_id'),
            'mine' => $query->whereIn('status', $active)->where('assigned_admin_id', $admin->getKey()),
            'awaiting_reply' => $query->whereIn('status', $active)->whereNotNull('last_requester_message_at')->where(fn (Builder $q) => $q->whereNull('last_support_message_at')->orWhereColumn('last_support_message_at', '<', 'last_requester_message_at')),
            'waiting_customer' => $query->where('status', SupportCaseStatus::WaitingForCustomer->value),
            'waiting_restaurant' => $query->where('status', SupportCaseStatus::WaitingForRestaurant->value),
            'waiting_provider' => $query->where('status', SupportCaseStatus::WaitingForProvider->value),
            'waiting_finance' => $query->where('status', SupportCaseStatus::WaitingForFinance->value),
            'waiting_internal' => $query->where('status', SupportCaseStatus::WaitingForInternal->value),
            'escalated' => $query->whereIn('status', $active)->whereNotNull('escalation_level'),
            'restricted' => $query->whereIn('status', $active)->where('classification', SupportCase::RESTRICTED),
            'sla_at_risk' => $query->whereIn('status', $active)->where('sla_status', SupportSlaStatus::AtRisk->value),
            'sla_breached' => $query->whereIn('status', $active)->where('sla_status', SupportSlaStatus::Breached->value),
            'reopened' => $query->where('status', SupportCaseStatus::Reopened->value),
            'resolved' => $query->where('status', SupportCaseStatus::Resolved->value),
            'closed' => $query->whereIn('status', [SupportCaseStatus::Closed->value, SupportCaseStatus::Cancelled->value]),
            default => $query->whereRaw('false'),
        };
    }

    /**
     * Search is by EXACT references: a case number, an order number, the reference or id of a linked payment,
     * refund, settlement or payout — or the name of the restaurant the case is about. Never a customer's name,
     * phone number or e-mail address, and never the text of messages or internal notes.
     */
    private function search(Builder $query, string $text): void
    {
        $upper = strtoupper($text);
        $query->where(function (Builder $q) use ($text, $upper): void {
            $q->where('case_number', $upper)
                ->orWhereIn('id', SupportCaseLink::query()->where(fn (Builder $l) => Str::isUuid($text) ? $l->where('entity_public_id', strtolower($text)) : $l->where('reference', $upper)->orWhere('reference', $text))->select('case_id'));
            if (Str::isUuid($text)) {
                $q->orWhere('public_id', strtolower($text));
            } elseif (mb_strlen($text) >= 3) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)).'%';
                $q->orWhereIn('location_id', DB::table('restaurant_locations')->whereRaw('lower(name) like ?', [$like])->select('id'));
            }
        });
    }

    /**
     * Numbers of a set of cases, from the cases themselves.
     *
     * @param  \Closure(): Builder<SupportCase>  $fresh
     * @return array<string, mixed>
     */
    private static function numbers(Builder $scope, \Closure $fresh): array
    {
        $active = SupportCaseStatus::activeValues();
        $by = fn (string $column, bool $onlyActive = true): array => $fresh()->when($onlyActive, fn (Builder $q) => $q->whereIn('status', $active))->toBase()->selectRaw($column.' as k, count(*) as n')->groupBy($column)->pluck('n', 'k')->map(fn ($n): int => (int) $n)->all();
        $status = $by('status', false);
        $since = CarbonImmutable::now('UTC')->subDays(30);
        $minutes = fn (string $from, string $to, Builder $q): ?float => ($v = $q->whereNotNull($to)->where($to, '>=', $since)->toBase()->selectRaw('avg(extract(epoch from ('.$to.' - '.$from.')) / 60) as m')->value('m')) === null ? null : round((float) $v, 1);
        $count = fn (\Closure $narrow): int => (int) $narrow($fresh()->whereIn('status', $active))->count();

        return [
            'total' => (int) array_sum($status),
            'open' => (int) array_sum(array_intersect_key($status, array_flip($active))),
            'unassigned' => $count(fn (Builder $q) => $q->whereNull('assigned_admin_id')),
            'urgent' => $count(fn (Builder $q) => $q->where('priority', SupportCasePriority::Urgent->value)),
            'high' => $count(fn (Builder $q) => $q->where('priority', SupportCasePriority::High->value)),
            'escalated' => $count(fn (Builder $q) => $q->whereNotNull('escalation_level')),
            'restricted' => $count(fn (Builder $q) => $q->where('classification', SupportCase::RESTRICTED)),
            'waiting' => (object) array_intersect_key($status, array_flip(array_map(fn (SupportCaseStatus $s): string => $s->value, SupportCaseStatus::waiting()))),
            'resolved' => (int) ($status[SupportCaseStatus::Resolved->value] ?? 0),
            'closed' => (int) ($status[SupportCaseStatus::Closed->value] ?? 0),
            'cancelled' => (int) ($status[SupportCaseStatus::Cancelled->value] ?? 0),
            'reopened' => (int) ($status[SupportCaseStatus::Reopened->value] ?? 0),
            'reopened_ever' => (int) $fresh()->where('reopen_count', '>', 0)->count(),
            'sla_at_risk' => $count(fn (Builder $q) => $q->where('sla_status', SupportSlaStatus::AtRisk->value)),
            'sla_breached' => $count(fn (Builder $q) => $q->where('sla_status', SupportSlaStatus::Breached->value)),
            'sla_not_measured' => $count(fn (Builder $q) => $q->where('sla_status', SupportSlaStatus::NotApplicable->value)),
            'by_status' => (object) $status,
            'by_category' => (object) $by('category'),
            'by_priority' => (object) $by('priority'),
            'by_team' => (object) $by('assigned_team'),
            'by_requester' => (object) $by('requester_type'),
            'created_last_7_days' => (int) $fresh()->where('created_at', '>=', CarbonImmutable::now('UTC')->subDays(7))->count(),
            // Averages over the last 30 days, from the cases' own timestamps; null when nothing was answered / resolved.
            'first_response_minutes_avg_30d' => $minutes('created_at', 'first_response_at', $fresh()),
            'resolution_minutes_avg_30d' => $minutes('created_at', 'resolved_at', $fresh()),
        ];
    }

    /**
     * @return array{0: int, 1: array<int, string>, 2: string, 3: int}
     */
    private function page(Request $request, int $default): array
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('support.limits.max_page_size')],
        ]);

        return [(int) ($input['per_page'] ?? $default), ['*'], 'page', (int) ($input['page'] ?? 1)];
    }

    /**
     * Internal ids of the markets the caller holds the permission for; null = every market. 403 when none.
     *
     * @return list<int>|null
     */
    private function marketKeys(Request $request, Permission $permission): ?array
    {
        $markets = $this->marketsWith($request, $permission);

        return $markets === null ? null : Market::query()->whereIn('public_id', $markets)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The same, for a permission the caller need not hold: false = holds it nowhere.
     *
     * @return list<int>|false|null
     */
    private function optionalMarketKeys(Request $request, Permission $permission): array|false|null
    {
        try {
            return $this->marketKeys($request, $permission);
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
    }

    private function holds(AdminUser $admin, Permission $permission, ?Market $market): bool
    {
        return $market === null ? Gate::forUser($admin)->allows($permission->value) : Gate::forUser($admin)->allows($permission->value, Scope::market($market->public_id));
    }

    private function admin(Request $request): AdminUser
    {
        return $request->user();
    }

    private static function day(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC') ?: throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw new ApiException(422, 'validation_failed', 'A date filter must be a day (YYYY-MM-DD).', ['fields' => ['filter' => ['A date filter must be a day (YYYY-MM-DD).']]]);
        }
    }
}
