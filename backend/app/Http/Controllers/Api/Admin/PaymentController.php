<?php

namespace App\Http\Controllers\Api\Admin;

use App\Auth\Principal;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\PaymentPresenter;
use App\Http\Support\ListQuery;
use App\Models\Market;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Services\Audit\AuditRecorder;
use App\Services\Payment\PaymentMetrics;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\PaymentReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Payments for administrators (Module 30): the real payment attempts, their timelines and what the provider sent.
 *
 * Reading needs admin.payments.view (for a market, or for all). The one thing an administrator can DO is ask the
 * provider again (admin.payments.reconcile, with a reason, audited) — and that changes only what the provider
 * confirms. There is no "mark as paid" and no way to edit a status, an amount or a provider reference. Refunds are
 * their own domain (Module 35, RefundController): a payment's refunds are listed here, and its state is never
 * changed by one — a refunded payment is still VERIFIED.
 */
class PaymentController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(
        private readonly PaymentPresenter $presenter,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $visible = $this->marketsWith($request, Permission::AdminPaymentsView);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $query = PaymentAttempt::query()->with(['customer', 'location', 'market'])
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id')));
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            // Exact references only: a payment id, its support reference, the checkout it paid, or a provider reference.
            $query->where(function (Builder $q) use ($text): void {
                $q->where('provider_order_reference', $text)->orWhere('provider_payment_reference', $text);
                if (Str::isUuid($text)) {
                    $q->orWhere('public_id', $text)->orWhere('checkout_public_id', $text);
                }
                if (preg_match('/\APAY-([0-9A-Fa-f]{10})\z/', $text, $m) === 1) {
                    $q->orWhereRaw("replace(public_id::text, '-', '') like ?", [strtolower($m[1]).'%']);
                }
            });
        }
        $list = new ListQuery(
            $request,
            filterable: ['status', 'provider', 'method', 'currency', 'fulfillment', 'restaurant', 'attention', 'from', 'to'],
            sortable: ['created_at', 'verified_at', 'amount_minor', 'status'],
            defaultSort: '-created_at',
            handlers: [
                'status' => fn (Builder $q, string $v) => $q->where('status', PaymentStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'method' => fn (Builder $q, string $v) => $q->where('method_type', strtoupper($v)),
                'currency' => fn (Builder $q, string $v) => $q->where('currency', strtoupper($v)),
                'fulfillment' => fn (Builder $q, string $v) => $q->where('fulfillment_status', PaymentFulfillmentStatus::tryFrom(strtoupper($v))?->value ?? '-'),
                'restaurant' => fn (Builder $q, string $v) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', Str::isUuid($v) ? $v : null)->select('id')),
                // Money that needs a person, and paid checkouts that cannot become an order as they stand.
                'attention' => fn (Builder $q, string $v) => in_array($v, ['1', 'true'], true)
                    ? $q->where(fn (Builder $w) => $w->where('status', PaymentStatus::ReviewRequired->value)->orWhere('fulfillment_status', PaymentFulfillmentStatus::RecoveryRequired->value))
                    : $q,
                'from' => fn (Builder $q, string $v) => $q->where('created_at', '>=', self::day($v)),
                'to' => fn (Builder $q, string $v) => $q->where('created_at', '<', self::day($v)->addDay()),
            ],
        );

        return JsonResource::collection($list->paginate($query)->through(fn (PaymentAttempt $p): array => $this->presenter->forAdmin($p)))->response();
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        return response()->json(['payment' => $this->presenter->detailForAdmin($this->find($payment, Permission::AdminPaymentsView))]);
    }

    /**
     * Asks the provider again. Whatever comes of it is the provider's word, not the administrator's.
     */
    public function reconcile(Request $request, string $payment): JsonResponse
    {
        $attempt = $this->find($payment, Permission::AdminPaymentsReconcile);
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // Nothing about a payment can be set by hand.
            ...array_fill_keys(['status', 'amount', 'amount_minor', 'currency', 'verified', 'verified_at', 'paid', 'provider_payment_reference', 'provider_order_reference', 'fulfillment_status'], ['prohibited']),
        ], ['prohibited' => 'The :attribute field cannot be set by hand.']);
        /** @var Principal $admin */
        $admin = $request->user();
        $before = $attempt->status->value;
        $after = $this->reconciliation->reconcile($attempt, PaymentAttemptEvent::SOURCE_ADMIN, [], (int) $admin->getAuthIdentifier());
        $this->audit->record('payment.reconciled', $after, $admin, ['status' => ['from' => $before, 'to' => $after->status->value]], (string) $input['reason'], $after->market_id);

        return response()->json(['payment' => $this->presenter->detailForAdmin($after->load(['customer', 'location', 'market', 'checkout', 'events', 'webhookEvents', 'refunds', 'refundBalance', 'disputes']))]);
    }

    public function metrics(PaymentMetrics $metrics, PaymentProviderManager $providers): JsonResponse
    {
        return response()->json($metrics->report($providers));
    }

    private function find(string $payment, Permission $permission): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->with(['customer', 'location', 'market', 'checkout', 'events', 'webhookEvents', 'refunds', 'refundBalance', 'disputes'])->where('public_id', $payment)->first()
            ?? throw ApiException::notFound('payment_not_found', 'This payment does not exist.');
        $this->authorizeFor($permission, $attempt->market);

        return $attempt;
    }

    /**
     * The permission for the payment's market — or, for a payment whose market is gone, for all markets.
     */
    private function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
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
