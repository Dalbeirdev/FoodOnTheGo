<?php

namespace App\Services\Support;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\SettlementStatus;
use App\Enums\SupportLinkType;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Market;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\ReconciliationDifference;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantPayout;
use App\Models\RestaurantReview;
use App\Models\RestaurantSettlement;
use App\Models\RestaurantUser;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseLink;
use App\Services\Order\OrderStore;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Restaurant\RestaurantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What a support case is about (Module 37): typed references to orders, payments, refunds, settlements, payouts,
 * disputes, reviews, reconciliation differences, restaurants and customers.
 *
 * WHO MAY LINK WHAT. A customer links only their own order, payment or refund. A restaurant member links only an
 * order, settlement or payout of a location they may open (and money only with the finance permission). An
 * administrator links what they may READ in that domain — a case permission opens no domain by itself. Anything else
 * "does not exist" for the caller.
 *
 * WHAT A LINK HOLDS. The key of the record and the name it had when it was linked. Never its state: summaries are
 * read from the record itself each time the case is shown, so a case can never disagree with the order, the payment
 * or the refund it is about.
 */
final class SupportLinkService
{
    /** What an administrator must be able to read to link — and to see — a record of each kind. */
    public const VIEW = [
        'ORDER' => Permission::AdminOrdersView,
        'PAYMENT' => Permission::AdminPaymentsView,
        'REFUND' => Permission::AdminRefundsView,
        'SETTLEMENT' => Permission::AdminSettlementsView,
        'PAYOUT' => Permission::AdminFinancePayoutsView,
        'DISPUTE' => Permission::AdminDisputesView,
        'REVIEW' => Permission::AdminReviewsView,
        'DIFFERENCE' => Permission::AdminFinanceReconciliationView,
        'LOCATION' => Permission::AdminRestaurantsView,
        'ORGANIZATION' => Permission::AdminRestaurantsView,
        'CUSTOMER' => Permission::AdminCustomersView,
    ];

    public function __construct(
        private readonly OrderStore $orders,
        private readonly PaymentAttemptStore $payments,
        private readonly RestaurantAccess $access,
        private readonly SupportCaseStateMachine $machine,
        private readonly SupportMetrics $metrics,
    ) {}

    /* ------------------------------------------------------------------ resolving a reference for a caller */

    /**
     * @return array{0: SupportLinkType, 1: Model, 2: string}
     *
     * @throws ApiException 404 when the record is not the customer's own
     */
    public function resolveForCustomer(Customer $customer, SupportLinkType $type, string $reference): array
    {
        $reference = trim($reference);
        $record = match ($type) {
            SupportLinkType::Order => $this->orders->ownedBy($customer, $reference),
            SupportLinkType::Payment => Str::isUuid($reference)
                ? $this->payments->ownedBy($customer, $reference)
                : (self::derived(PaymentAttempt::query()->where('customer_id', $customer->getKey()), 'PAY', $reference)->first() ?? throw ApiException::notFound('payment_not_found', 'This payment does not exist.')),
            SupportLinkType::Refund => self::derived(Refund::query()->where('customer_id', $customer->getKey()), 'RFD', $reference)->first()
                ?? throw ApiException::notFound('refund_not_found', 'This refund does not exist.'),
            default => throw new ApiException(422, 'validation_failed', 'This cannot be linked here.', ['fields' => ['links' => ['Only your own order, payment or refund can be attached.']]]),
        };

        return [$type, $record, self::referenceOf($type, $record)];
    }

    /**
     * @return array{0: SupportLinkType, 1: Model, 2: string}
     *
     * @throws ApiException 404 when the record is outside the member's locations
     */
    public function resolveForRestaurant(RestaurantUser $user, SupportLinkType $type, string $reference): array
    {
        $reference = trim($reference);
        $locations = fn (): array => $this->access->locations($user)->pluck('restaurant_locations.id')->all();
        $record = match ($type) {
            SupportLinkType::Order => $this->orders->forRestaurant($user, $reference),
            SupportLinkType::Settlement => RestaurantSettlement::query()->with('location.organization')->whereIn('location_id', $locations())->where('status', '<>', SettlementStatus::Cancelled->value)
                ->where(fn (Builder $q) => Str::isUuid($reference) ? $q->where('public_id', $reference) : $q->where('reference', strtoupper($reference)))->first()
                ?? throw ApiException::notFound('settlement_not_found', 'This settlement does not exist.'),
            SupportLinkType::Payout => self::derived(RestaurantPayout::query()->with('location.organization')->whereIn('location_id', $locations()), 'PO', $reference)->first()
                ?? throw ApiException::notFound('payout_not_found', 'This payout does not exist.'),
            default => throw new ApiException(422, 'validation_failed', 'This cannot be linked here.', ['fields' => ['links' => ['Only an order, a settlement or a payout of your restaurant can be attached.']]]),
        };
        if ($type !== SupportLinkType::Order) {
            // Money: theirs, and only with the finance permission (403 — the record is theirs, the permission is missing).
            $this->access->authorize($user, $record->location, Permission::RestaurantFinanceView);
        }

        return [$type, $record, self::referenceOf($type, $record)];
    }

    /**
     * @return array{0: SupportLinkType, 1: Model, 2: string}
     *
     * @throws ApiException 404 when it does not exist, 403 when the administrator may not read that domain there
     */
    public function resolveForAdmin(AdminUser $admin, SupportLinkType $type, string $reference): array
    {
        $reference = trim($reference);
        $uuid = Str::isUuid($reference);
        $missing = fn (string $what): ApiException => ApiException::notFound('linked_record_not_found', 'No '.$what.' with this reference exists.');
        $record = match ($type) {
            SupportLinkType::Order => $this->orders->byReference(Order::query(), $reference)->first() ?? throw $missing('order'),
            SupportLinkType::Payment => self::derived(PaymentAttempt::query(), 'PAY', $reference)->first() ?? throw $missing('payment'),
            SupportLinkType::Refund => self::derived(Refund::query(), 'RFD', $reference)->first() ?? throw $missing('refund'),
            SupportLinkType::Settlement => RestaurantSettlement::query()->where(fn (Builder $q) => $uuid ? $q->where('public_id', $reference) : $q->where('reference', strtoupper($reference)))->first() ?? throw $missing('settlement'),
            SupportLinkType::Payout => self::derived(RestaurantPayout::query(), 'PO', $reference)->first() ?? throw $missing('payout'),
            SupportLinkType::Dispute => PaymentDispute::query()->where(fn (Builder $q) => $uuid ? $q->where('public_id', $reference) : $q->where('provider_dispute_reference', $reference))->first() ?? throw $missing('dispute'),
            SupportLinkType::Review => ($uuid ? RestaurantReview::query()->where('public_id', $reference)->first() : null) ?? throw $missing('review'),
            SupportLinkType::Difference => ($uuid ? ReconciliationDifference::query()->where('public_id', $reference)->first() : null) ?? throw $missing('reconciliation difference'),
            SupportLinkType::Location => ($uuid ? RestaurantLocation::query()->where('public_id', $reference)->first() : null) ?? throw $missing('restaurant'),
            SupportLinkType::Organization => ($uuid ? RestaurantOrganization::query()->where('public_id', $reference)->first() : null) ?? throw $missing('restaurant organization'),
            // By the customer's own id only — never by a name, a phone number or an e-mail address.
            SupportLinkType::Customer => ($uuid ? Customer::query()->where('public_id', $reference)->first() : null) ?? throw $missing('customer'),
        };
        if (! $this->adminMayRead($admin, $type, $record)) {
            $this->metrics->record('link_refused');
            throw new ApiException(403, 'linked_record_not_authorized', 'You may not read this kind of record, so you cannot attach it to a case.', ['required_permission' => self::VIEW[$type->value]->value]);
        }

        return [$type, $record, self::referenceOf($type, $record)];
    }

    /** Whether an administrator holds the DOMAIN's own read permission for the record's market. */
    public function adminMayRead(AdminUser $admin, SupportLinkType $type, Model $record): bool
    {
        return $this->adminAllows($admin, self::VIEW[$type->value], self::marketIdOf($type, $record));
    }

    public function adminAllows(AdminUser $admin, Permission $permission, ?int $marketId): bool
    {
        $market = $marketId === null ? null : Market::query()->find($marketId);

        return $market === null ? $admin->can($permission->value) : Gate::forUser($admin)->allows($permission->value, Scope::market($market->public_id));
    }

    /* ------------------------------------------------------------------ attaching */

    /**
     * Attaches a record to a case. The caller is inside a transaction and holds the case. Attaching the same record
     * again answers the link that exists.
     */
    public function attach(SupportCase $case, SupportLinkType $type, Model $record, string $reference, SupportActor $actor, bool $line = true): SupportCaseLink
    {
        $publicId = (string) $record->getAttribute('public_id');
        $existing = SupportCaseLink::query()->where('case_id', $case->getKey())->where('link_type', $type->value)->where('entity_public_id', $publicId)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (SupportCaseLink::query()->where('case_id', $case->getKey())->count() >= (int) config('support.limits.links_per_case')) {
            throw ApiException::conflict('case_links_full', 'This case already points at as many records as a case can.');
        }
        $link = (new SupportCaseLink)->forceFill([
            'case_id' => $case->getKey(),
            'link_type' => $type->value,
            $type->column() => $record->getKey(),
            'entity_public_id' => $publicId,
            'reference' => mb_substr($reference, 0, 80),
            'added_by_type' => $actor->type,
            'added_by_admin_id' => $actor->admin?->getKey(),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
        try {
            DB::transaction(fn () => $link->save()); // a savepoint: a concurrent identical link leaves the transaction usable
        } catch (UniqueConstraintViolationException) {
            return SupportCaseLink::query()->where('case_id', $case->getKey())->where('link_type', $type->value)->where('entity_public_id', $publicId)->firstOrFail();
        }
        $this->metrics->record('link_added');
        if ($line) {
            $this->machine->line($case, SupportCaseEvent::ENTITY_LINKED, $actor, SupportCaseEvent::INTERNAL, ['link_type' => $type->value, 'reference' => $link->reference]);
        }

        return $link;
    }

    /* ------------------------------------------------------------------ reading the linked records LIVE */

    /**
     * What the requester reads about the records their case points at: only the kinds they could link themselves,
     * in the words their own screens use. Read from the records now.
     *
     * @param  list<int>|null  $financeLocationIds  restaurant: the locations whose money the viewer may read
     * @return list<array<string, mixed>>
     */
    public function summariesForRequester(SupportCase $case, bool $customer, ?array $financeLocationIds = null): array
    {
        $allowed = array_map(fn (SupportLinkType $t): string => $t->value, $customer ? SupportLinkType::forCustomer() : SupportLinkType::forRestaurant());
        $out = [];
        foreach ($this->linksOf($case) as $link) {
            if (! in_array($link->link_type->value, $allowed, true)) {
                continue;
            }
            $record = $this->recordOf($link);
            if ($record === null) {
                continue;
            }
            // The desk can attach any record it may read — also one of somebody else (a mistyped number is enough).
            // A requester is shown a linked record only when it is their own, whoever attached it.
            if (! $this->belongsToRequester($case, $customer, $record)) {
                continue;
            }
            $row = match ($link->link_type) {
                SupportLinkType::Order => [
                    'order_number' => (string) $record->order_number,
                    'status' => $record->status->value,
                    'restaurant' => (string) ($record->location?->name ?? ''),
                    'total' => self::money((int) $record->total_minor, (string) $record->currency),
                    'placed_at' => self::utc($record->placed_at),
                    'refund_status' => $customer ? $record->refund_status?->forCustomer() : $record->refund_status?->value,
                ],
                SupportLinkType::Payment => ['status' => self::paymentWord($record->status->value), 'amount' => self::money((int) $record->amount_minor, (string) $record->currency), 'order_created' => $record->fulfillment_status?->value === 'ORDER_CREATED'],
                SupportLinkType::Refund => ['status' => $record->status->forCustomer() ?? 'UNDER_REVIEW', 'amount' => self::money((int) ($record->approved_amount_minor ?? $record->requested_amount_minor), (string) $record->currency), 'order_number' => $record->order_number],
                SupportLinkType::Settlement => $financeLocationIds !== null && in_array((int) $record->location_id, $financeLocationIds, true)
                    ? ['status' => $record->status->forRestaurant(), 'net_payable' => self::money((int) $record->net_payable_minor, (string) $record->currency), 'period_start' => self::utc($record->period_start), 'period_end' => self::utc($record->period_end), 'restaurant' => (string) $record->restaurant_name]
                    : null,
                SupportLinkType::Payout => $financeLocationIds !== null && in_array((int) $record->location_id, $financeLocationIds, true)
                    ? ['status' => $record->status->forRestaurant(), 'amount' => self::money((int) $record->amount_minor, (string) $record->currency), 'restaurant' => (string) $record->restaurant_name]
                    : null,
                default => null,
            };
            if ($row === null) {
                continue;
            }
            $out[] = ['id' => (string) $link->public_id, 'type' => $link->link_type->value, 'reference' => (string) $link->reference, 'record_id' => (string) $link->entity_public_id] + $row;
        }

        return $out;
    }

    /**
     * Whether a linked record is the requester's own: the customer's (their order, payment, refund) or the
     * restaurant organization's (an order, settlement or payout of one of its locations).
     */
    private function belongsToRequester(SupportCase $case, bool $customer, Model $record): bool
    {
        if ($customer) {
            return $case->customer_id !== null && $record->getAttribute('customer_id') !== null && (int) $record->getAttribute('customer_id') === (int) $case->customer_id;
        }
        if ($case->organization_id === null) {
            return false;
        }
        $organization = $record->getAttribute('organization_id');
        if ($organization === null && ($location = $record->getAttribute('location_id')) !== null) {
            $organization = DB::table('restaurant_locations')->where('id', $location)->value('organization_id');
        }

        return $organization !== null && (int) $organization === (int) $case->organization_id;
    }

    /**
     * The service desk's read-only panels: each linked record as its own domain has it NOW — or, when the viewer may
     * not read that domain, the fact that a record is linked and which permission is missing.
     *
     * @return list<array<string, mixed>>
     */
    public function panelsForAdmin(SupportCase $case, AdminUser $admin): array
    {
        $out = [];
        foreach ($this->linksOf($case) as $link) {
            $type = $link->link_type;
            $record = $this->recordOf($link);
            $base = ['id' => (string) $link->public_id, 'type' => $type->value, 'reference' => (string) $link->reference, 'record_id' => (string) $link->entity_public_id, 'linked_at' => self::utc($link->created_at), 'linked_by' => (string) $link->added_by_type];
            if ($record === null) {
                $out[] = $base + ['visible' => false, 'reason' => 'RECORD_GONE'];

                continue;
            }
            if (! $this->adminMayRead($admin, $type, $record)) {
                // The reference stays readable (it is how the case is found); the record's state does not.
                $out[] = $base + ['visible' => false, 'reason' => 'PERMISSION', 'required_permission' => self::VIEW[$type->value]->value];

                continue;
            }
            $out[] = $base + ['visible' => true, 'read_at' => self::utc(CarbonImmutable::now('UTC')), 'record' => $this->summary($type, $record), 'shortcuts' => $this->shortcuts($admin, $type, $record, $this->adminAllows($admin, Permission::AdminSupportManage, $case->market_id))];
        }

        return $out;
    }

    /**
     * A payment's state as a requester may read it: paid or not, or still being checked. UNKNOWN, REVIEW_REQUIRED and
     * the provider's intermediate states are the payment domain's own words and stay there.
     */
    public static function paymentWord(string $status): string
    {
        return in_array($status, ['VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED'], true) ? $status : 'PROCESSING';
    }

    /**
     * Actions of OTHER domains that are relevant to a linked record. Each belongs to its domain and needs that
     * domain's permission: `allowed` is the viewer's — a support permission never makes it true, and without the
     * permission to work the case ($worksCase) nothing is allowed either (the endpoint asks for both).
     *
     * @return list<array{action: string, allowed: bool, required_permission: string}>
     */
    public function shortcuts(AdminUser $admin, SupportLinkType $type, Model $record, bool $worksCase = true): array
    {
        $market = self::marketIdOf($type, $record);
        $one = fn (string $action, Permission $p): array => ['action' => $action, 'allowed' => $worksCase && $this->adminAllows($admin, $p, $market), 'required_permission' => $p->value];
        $out = [];
        if ($type === SupportLinkType::Payment) {
            $out[] = $one(SupportDomainActionService::PAYMENT_RECONCILE, Permission::AdminPaymentsReconcile);
            if ($record->fulfillment_status?->value === 'RECOVERY_REQUIRED') {
                $out[] = $one(SupportDomainActionService::ORDER_RECOVER, Permission::AdminOrdersRecover);
            }
        }
        if ($type === SupportLinkType::Refund) {
            $out[] = $one(SupportDomainActionService::REFUND_RECONCILE, Permission::AdminRefundsReconcile);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(SupportLinkType $type, Model $record): array
    {
        return match ($type) {
            SupportLinkType::Order => [
                'order_number' => (string) $record->order_number, 'status' => $record->status->value, 'payment_state' => $record->payment_state?->value, 'refund_status' => $record->refund_status?->value,
                'total' => self::money((int) $record->total_minor, (string) $record->currency), 'restaurant' => (string) ($record->location?->name ?? ''),
                'placed_at' => self::utc($record->placed_at), 'pickup_at' => self::utc($record->pickup_at), 'ready_at' => self::utc($record->ready_at), 'picked_up_at' => self::utc($record->picked_up_at),
                'completed_at' => self::utc($record->completed_at), 'cancelled_at' => self::utc($record->cancelled_at), 'rejection_reason' => $record->rejection_reason, 'version' => (int) $record->version,
            ],
            SupportLinkType::Payment => [
                'support_reference' => $record->supportReference(), 'status' => $record->status->value, 'status_reason' => $record->status_reason,
                'order_creation' => $record->fulfillment_status?->value, 'order_creation_reason' => $record->fulfillment_reason,
                'amount' => self::money((int) $record->amount_minor, (string) $record->currency), 'provider' => (string) $record->provider, 'provider_mode' => $record->provider_mode,
                'provider_payment_reference' => $record->provider_payment_reference, 'attempt_number' => (int) $record->attempt_number, 'restaurant' => (string) ($record->restaurant_name ?? ''),
                'verified_at' => self::utc($record->verified_at), 'last_reconciled_at' => self::utc($record->last_reconciled_at),
                'fulfillment_id' => OrderFulfillment::query()->where('payment_attempt_id', $record->getKey())->value('public_id'),
            ],
            SupportLinkType::Refund => [
                'support_reference' => $record->supportReference(), 'status' => $record->status->value, 'review_reason' => $record->review_reason, 'type' => $record->type,
                'amount' => self::money((int) ($record->approved_amount_minor ?? $record->requested_amount_minor), (string) $record->currency), 'order_number' => $record->order_number,
                'reason_code' => $record->reason_code, 'failure_category' => $record->failure_category, 'provider_status' => $record->provider_status,
                'requested_at' => self::utc($record->requested_at), 'completed_at' => self::utc($record->completed_at), 'version' => (int) $record->version,
            ],
            SupportLinkType::Settlement => [
                'reference' => (string) $record->reference, 'status' => $record->status->value, 'restaurant' => (string) $record->restaurant_name,
                'period_start' => self::utc($record->period_start), 'period_end' => self::utc($record->period_end), 'order_count' => (int) $record->order_count, 'line_count' => (int) $record->line_count,
                'gross_sales' => self::money((int) $record->gross_sales_minor, (string) $record->currency), 'commission' => self::money((int) $record->commission_minor, (string) $record->currency),
                'refund_adjustments' => self::money((int) $record->refund_adjustments_minor, (string) $record->currency), 'other_adjustments' => self::money((int) $record->other_adjustments_minor, (string) $record->currency),
                'net_payable' => self::money((int) $record->net_payable_minor, (string) $record->currency), 'paid' => self::money((int) $record->paid_minor, (string) $record->currency), 'paid_at' => self::utc($record->paid_at),
            ],
            SupportLinkType::Payout => [
                'support_reference' => $record->supportReference(), 'status' => $record->status->value, 'restaurant' => (string) $record->restaurant_name, 'method' => $record->method,
                'amount' => self::money((int) $record->amount_minor, (string) $record->currency), 'provider' => $record->provider, 'provider_status' => $record->provider_status,
                'provider_payout_reference' => $record->provider_payout_reference, 'failure_code' => $record->failure_code, 'review_reason' => $record->review_reason,
                'requested_at' => self::utc($record->requested_at), 'completed_at' => self::utc($record->completed_at),
            ],
            SupportLinkType::Dispute => [
                'provider_dispute_reference' => (string) $record->provider_dispute_reference, 'status' => $record->status->value, 'phase' => $record->phase, 'reason_code' => $record->reason_code,
                'amount' => self::money((int) $record->amount_minor, (string) $record->currency), 'order_number' => $record->order_number, 'restaurant' => (string) ($record->restaurant_name ?? ''),
                'opened_at' => self::utc($record->opened_at), 'respond_by_at' => self::utc($record->respond_by_at), 'closed_at' => self::utc($record->closed_at),
                // Nobody answers a dispute from FoodOnTheGo: the provider's own dashboard is where that happens.
                'response' => 'NOT_PROVIDED',
            ],
            SupportLinkType::Review => ['status' => $record->status->value, 'overall_rating' => (int) $record->overall_rating, 'submitted_at' => self::utc($record->submitted_at)],
            SupportLinkType::Difference => [
                'type' => $record->type->value, 'severity' => (string) $record->severity, 'status' => $record->status->value, 'subject_reference' => $record->subject_reference,
                'expected' => $record->expected_minor === null || $record->currency === null ? null : self::money((int) $record->expected_minor, (string) $record->currency),
                'actual' => $record->actual_minor === null || $record->currency === null ? null : self::money((int) $record->actual_minor, (string) $record->currency),
                'first_seen_at' => self::utc($record->first_seen_at), 'last_seen_at' => self::utc($record->last_seen_at),
            ],
            SupportLinkType::Location => ['name' => (string) $record->name, 'status' => $record->status->value, 'operational_status' => $record->operational_status?->value],
            SupportLinkType::Organization => ['name' => (string) $record->display_name, 'status' => $record->status->value],
            // What a case needs of a customer: who it is — never the full phone number.
            SupportLinkType::Customer => ['name' => (string) ($record->name ?? ''), 'status' => $record->status->value, 'phone' => self::maskPhone((string) $record->phone_e164)],
        };
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @return Collection<int, SupportCaseLink>
     */
    private function linksOf(SupportCase $case)
    {
        return $case->relationLoaded('links') ? $case->links->sortBy('id')->values() : SupportCaseLink::query()->where('case_id', $case->getKey())->orderBy('id')->get();
    }

    public function recordOf(SupportCaseLink $link): ?Model
    {
        $key = $link->entityKey();

        return match ($link->link_type) {
            SupportLinkType::Order => Order::query()->with('location')->find($key),
            SupportLinkType::Payment => PaymentAttempt::query()->find($key),
            SupportLinkType::Refund => Refund::query()->find($key),
            SupportLinkType::Settlement => RestaurantSettlement::query()->find($key),
            SupportLinkType::Payout => RestaurantPayout::query()->find($key),
            SupportLinkType::Dispute => PaymentDispute::query()->find($key),
            SupportLinkType::Review => RestaurantReview::query()->find($key),
            SupportLinkType::Difference => ReconciliationDifference::query()->find($key),
            SupportLinkType::Location => RestaurantLocation::query()->find($key),
            SupportLinkType::Organization => RestaurantOrganization::query()->find($key),
            SupportLinkType::Customer => Customer::query()->find($key),
        };
    }

    public static function referenceOf(SupportLinkType $type, Model $record): string
    {
        return match ($type) {
            SupportLinkType::Order => (string) $record->order_number,
            SupportLinkType::Payment, SupportLinkType::Refund, SupportLinkType::Payout => $record->supportReference(),
            SupportLinkType::Settlement => (string) $record->reference,
            SupportLinkType::Dispute => (string) $record->provider_dispute_reference,
            SupportLinkType::Review => 'REV-'.strtoupper(substr(str_replace('-', '', (string) $record->public_id), 0, 10)),
            SupportLinkType::Difference => 'DIF-'.strtoupper(substr(str_replace('-', '', (string) $record->public_id), 0, 10)),
            SupportLinkType::Location => (string) $record->name,
            SupportLinkType::Organization => (string) $record->display_name,
            SupportLinkType::Customer => 'CUS-'.strtoupper(substr(str_replace('-', '', (string) $record->public_id), 0, 10)),
        };
    }

    public static function marketIdOf(SupportLinkType $type, Model $record): ?int
    {
        $id = match ($type) {
            SupportLinkType::Organization => $record->primary_market_id,
            default => $record->getAttribute('market_id'),
        };

        return $id === null ? null : (int) $id;
    }

    /**
     * A record addressed by its public id or by the short reference derived from it ("RFD-" + ten characters).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function derived(Builder $query, string $prefix, string $reference): Builder
    {
        if (Str::isUuid($reference)) {
            return $query->where('public_id', $reference);
        }
        if (preg_match('/\A'.$prefix.'-([0-9A-Fa-f]{10})\z/', $reference, $m) === 1) {
            return $query->whereRaw("replace(public_id::text, '-', '') like ?", [strtolower($m[1]).'%']);
        }

        return $query->whereRaw('false');
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private static function money(int $minor, string $currency): array
    {
        return ['amount' => $minor, 'currency' => $currency];
    }

    private static function utc(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String();
    }

    private static function maskPhone(string $phone): ?string
    {
        return $phone === '' ? null : '•••• '.substr($phone, -4);
    }
}
