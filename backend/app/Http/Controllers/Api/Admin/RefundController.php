<?php

namespace App\Http\Controllers\Api\Admin;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Refund\RefundPresenter;
use App\Http\Support\ListQuery;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Journey\ProviderMetrics;
use App\Services\Order\OrderStore;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Refund\RefundMetrics;
use App\Services\Refund\RefundService;
use App\Services\Refund\RefundStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Refunds for administrators (Module 35): the refund records, their queues and the few things a person can do.
 *
 *   read                      admin.refunds.view      (for a market, or for all)
 *   ask for a refund          admin.refunds.create    — a request; it moves no money
 *   approve / turn down       admin.refunds.approve   — who asked is not who approved
 *   send to the provider,
 *   try a failed one again    admin.refunds.issue     — the only actions that reach the provider
 *   ask the provider again    admin.refunds.reconcile — changes only what the provider confirms
 *
 * Every action that decides something needs a reason and is audited. NOTHING here sets a refund's status, amount,
 * currency, payment or provider reference by hand: there is no "mark as refunded", and the fields are refused when
 * they are sent. A refund is REFUNDED only on the provider's own record of it.
 */
class RefundController extends Controller
{
    use AuthorizesMarketScope;

    /** What no request may carry: the server derives or the provider decides every one of them. */
    private const PROTECTED = ['status', 'refund_status', 'provider', 'provider_refund_reference', 'provider_payment_reference', 'provider_status', 'payment_id', 'payment', 'order_id', 'customer_id', 'approved_amount', 'approved_amount_minor', 'completed_at', 'refunded', 'refunded_at', 'approved_by', 'requested_by'];

    private const QUEUES = [
        'required' => ['REQUIRED'],
        'awaiting_approval' => ['REQUIRED', 'REQUESTED'],
        'approved' => ['APPROVED'],
        'processing' => ['SUBMITTING', 'SUBMISSION_UNKNOWN', 'PROCESSING'],
        'failed' => ['FAILED'],
        'review_required' => ['REVIEW_REQUIRED'],
        'completed' => ['REFUNDED'],
        'cancelled' => ['CANCELLED'],
    ];

    public function __construct(
        private readonly RefundService $refunds,
        private readonly RefundStore $store,
        private readonly RefundPresenter $presenter,
        private readonly OrderStore $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $query = $this->visible($request)->with('market');
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            // Exact references only: a refund, its support reference, an order number, a payment, a provider refund.
            $query->where(function (Builder $q) use ($text): void {
                $q->where('provider_refund_reference', $text)->orWhere('order_number', strtoupper($text));
                if (Str::isUuid($text)) {
                    $q->orWhere('public_id', $text)->orWhere('payment_public_id', $text);
                }
                if (preg_match('/\ARFD-([0-9A-Fa-f]{10})\z/', $text, $m) === 1) {
                    $q->orWhereRaw("replace(public_id::text, '-', '') like ?", [strtolower($m[1]).'%']);
                }
                if (preg_match('/\APAY-([0-9A-Fa-f]{10})\z/', $text, $m) === 1) {
                    $q->orWhereRaw("replace(payment_public_id::text, '-', '') like ?", [strtolower($m[1]).'%']);
                }
            });
        }
        $list = new ListQuery(
            $request,
            filterable: ['status', 'queue', 'type', 'reason', 'provider', 'restaurant', 'market', 'review', 'from', 'to'],
            sortable: ['requested_at', 'completed_at', 'requested_amount_minor', 'status'],
            defaultSort: '-requested_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', RefundStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'queue' => fn (Builder $q, string $v) => $q->whereIn('status', self::QUEUES[strtolower($v)] ?? ['-']),
                'type' => fn (Builder $q, string $v) => $q->where('type', in_array(strtoupper($v), [Refund::TYPE_FULL, Refund::TYPE_PARTIAL], true) ? strtoupper($v) : '-'),
                'reason' => fn (Builder $q, string $v) => $q->where('reason_code', RefundReason::tryFrom(strtoupper($v))?->value ?? '-'),
                'provider' => fn (Builder $q, string $v) => $q->where('provider', strtolower($v)),
                'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
                'market' => fn (Builder $q, string $v) => $q->whereIn('market_id', Market::query()->where('slug', $v)->select('id')),
                // Everything a person has to look at: a refund that failed, is not understood, or needs a decision.
                'review' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true)
                    ? $q->whereIn('status', [RefundStatus::ReviewRequired->value, RefundStatus::Failed->value, RefundStatus::SubmissionUnknown->value])
                    : $q,
                'from' => fn (Builder $q, string $v) => $q->where('requested_at', '>=', self::day($v)),
                'to' => fn (Builder $q, string $v) => $q->where('requested_at', '<', self::day($v)->addDay()),
            ],
        );

        return JsonResource::collection($list->paginate($query)->through(fn (Refund $r): array => $this->presenter->forAdmin($r)))->response();
    }

    /**
     * How many refunds wait in each operational queue, for the markets the caller may see.
     */
    public function queues(Request $request): JsonResponse
    {
        $byStatus = $this->visible($request)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $queues = [];
        foreach (self::QUEUES as $name => $statuses) {
            $queues[$name] = (int) collect($statuses)->sum(fn (string $s): int => (int) ($byStatus[$s] ?? 0));
        }

        return response()->json(['queues' => $queues, 'total' => (int) $byStatus->sum()]);
    }

    public function show(Request $request, string $refund): JsonResponse
    {
        return $this->answer($request, $this->find($refund, Permission::AdminRefundsView));
    }

    /**
     * Asks for a refund of an order that is over — all that is left of its payment, or a part. The amount of a part
     * is a request: the server checks it against what the payment can still give back, at this moment.
     */
    public function storeForOrder(Request $request, string $order): JsonResponse
    {
        $found = $this->orders->byReference(Order::query(), $order)->with('market')->first()
            ?? throw ApiException::notFound('order_not_found', 'This order does not exist.');
        $this->authorizeFor(Permission::AdminRefundsCreate, $found->market);
        $input = $request->validate([
            'type' => ['required', 'string', Rule::in([Refund::TYPE_FULL, Refund::TYPE_PARTIAL])],
            // Validated as an integer of minor units here; whether it is positive and available is the refund domain's answer.
            'amount_minor' => ['nullable', 'integer', 'prohibited_if:type,'.Refund::TYPE_FULL],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'reason_code' => ['required', 'string', Rule::in(array_map(fn (RefundReason $r): string => $r->value, RefundReason::forOrderRequests()))],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn (): bool => RefundReason::tryFrom((string) $request->input('reason_code'))?->needsNote() ?? false)],
            ...array_fill_keys(self::PROTECTED, ['prohibited']),
        ], ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.']);
        if (isset($input['amount_minor'])) {
            $input['amount_minor'] = (int) $input['amount_minor'];
        }
        $refund = $this->refunds->requestForOrder($found, $this->admin($request), $input);

        return $this->answer($request, $this->find((string) $refund->public_id, Permission::AdminRefundsView), 201);
    }

    /**
     * Asks for the refund of a payment that never became an order (its order cannot be made, or it is a second
     * payment for a checkout already paid). Always the whole payment; the reason is read from the payment.
     */
    public function storeForPayment(Request $request, string $payment): JsonResponse
    {
        $attempt = PaymentAttempt::query()->with('market')->where('public_id', $payment)->first()
            ?? throw ApiException::notFound('payment_not_found', 'This payment does not exist.');
        $this->authorizeFor(Permission::AdminRefundsCreate, $attempt->market);
        $input = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
            ...array_fill_keys([...self::PROTECTED, 'amount', 'amount_minor', 'currency', 'type', 'reason_code'], ['prohibited']),
        ], ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.']);
        $refund = $this->refunds->requestForPayment($attempt, $this->admin($request), (string) $input['note']);

        return $this->answer($request, $this->find((string) $refund->public_id, Permission::AdminRefundsView), 201);
    }

    public function approve(Request $request, string $refund): JsonResponse
    {
        $found = $this->find($refund, Permission::AdminRefundsApprove);
        $input = $this->decision($request, reasonRequired: false);
        $this->refunds->approve($found, $this->admin($request), $input['reason'] ?? null, $input['expected_version'] ?? null);

        return $this->answer($request, $this->find($refund, Permission::AdminRefundsView));
    }

    public function reject(Request $request, string $refund): JsonResponse
    {
        $found = $this->find($refund, Permission::AdminRefundsApprove);
        $input = $this->decision($request, reasonRequired: true);
        $this->refunds->reject($found, $this->admin($request), (string) $input['reason'], $input['expected_version'] ?? null);

        return $this->answer($request, $this->find($refund, Permission::AdminRefundsView));
    }

    /**
     * Sends an approved refund to the provider. The amount is the approved one — recalculated nowhere in the browser.
     */
    public function execute(Request $request, string $refund): JsonResponse
    {
        $found = $this->find($refund, Permission::AdminRefundsIssue);
        $input = $this->decision($request, reasonRequired: false);
        $this->refunds->execute($found, $this->admin($request), $input['reason'] ?? null);

        return $this->answer($request, $this->find($refund, Permission::AdminRefundsView));
    }

    public function retry(Request $request, string $refund): JsonResponse
    {
        $found = $this->find($refund, Permission::AdminRefundsIssue);
        $input = $this->decision($request, reasonRequired: true);
        $this->refunds->retry($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($refund, Permission::AdminRefundsView));
    }

    /**
     * Asks the provider again. Whatever comes of it is the provider's word, not the administrator's.
     */
    public function reconcile(Request $request, string $refund): JsonResponse
    {
        $found = $this->find($refund, Permission::AdminRefundsReconcile);
        $input = $this->decision($request, reasonRequired: true);
        $this->refunds->reconcile($found, $this->admin($request), (string) $input['reason']);

        return $this->answer($request, $this->find($refund, Permission::AdminRefundsView));
    }

    public function metrics(RefundMetrics $metrics, PaymentProviderManager $providers, ProviderMetrics $providerMetrics): JsonResponse
    {
        return response()->json($metrics->report($providers, $providerMetrics));
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @return array{reason?: string|null, expected_version?: int|null}
     */
    private function decision(Request $request, bool $reasonRequired): array
    {
        return $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Nothing about a refund can be set by hand — not its state, its amount, its currency or its provider record.
            ...array_fill_keys([...self::PROTECTED, 'amount', 'amount_minor', 'currency', 'type', 'reason_code'], ['prohibited']),
        ], ['prohibited' => 'The :attribute field cannot be set by hand.']);
    }

    private function answer(Request $request, Refund $refund, int $status = 200): JsonResponse
    {
        $admin = $this->admin($request);
        $scope = $refund->market;
        $can = fn (Permission $p): bool => $scope === null ? $admin->can($p->value) : Gate::forUser($admin)->allows($p->value, Scope::market($scope->public_id));

        return response()->json(['refund' => $this->presenter->detailForAdmin($refund, $this->store->peek($refund->paymentAttempt), [
            'approve' => $can(Permission::AdminRefundsApprove),
            'reject' => $can(Permission::AdminRefundsApprove),
            'execute' => $can(Permission::AdminRefundsIssue),
            'retry' => $can(Permission::AdminRefundsIssue),
            'reconcile' => $can(Permission::AdminRefundsReconcile),
        ])], $status);
    }

    /**
     * @return Builder<Refund>
     */
    private function visible(Request $request): Builder
    {
        $markets = $this->marketsWith($request, Permission::AdminRefundsView);

        return Refund::query()->when($markets !== null, fn (Builder $q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $markets)->select('id')));
    }

    private function find(string $refund, Permission $permission): Refund
    {
        $found = Refund::query()->with(['market', 'order', 'paymentAttempt.disputes', 'approvedBy', 'closedBy', 'attempts', 'events', 'webhookEvents'])->where('public_id', $refund)->first()
            ?? throw ApiException::notFound('refund_not_found', 'This refund does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }

    /**
     * The permission for the record's market — or, for a record whose market is gone, for all markets.
     */
    private function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
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
