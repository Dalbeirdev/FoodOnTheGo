<?php

namespace App\Http\Controllers\Api\Dev;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ProviderFailure;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RestaurantLocation;
use App\Models\RestaurantPayout;
use App\Models\RestaurantPayoutProfile;
use App\Services\Finance\Payout\FakePayoutProvider;
use App\Services\Finance\Payout\PayoutProviderManager;
use App\Services\Finance\Payout\PayoutService;
use App\Services\Finance\ProviderSettlement\FakeSettlementSource;
use App\Services\Finance\ProviderSettlement\ProviderSettlementSources;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT ONLY — the fake providers' side of finance (Module 36). NO MONEY MOVES.
 *
 *   - the fake PAYOUT provider: a bank finishing, failing or returning a payout, a lost answer, a webhook;
 *   - a TEST payout destination for a location (a token and a mask from the fake provider — no bank detail, no KYC);
 *   - the fake PAYMENT provider's settlement report for a day, with the fee and tax the caller gives it.
 *
 * Nothing here shortcuts the finance domain: it only changes what the FAKE providers hold, or sends what a provider
 * would send (a signed webhook through the same verification). Whether a payout becomes PAID is still decided by the
 * payout service from the provider's record — there is no way here to set a payout's or a settlement's status.
 * Every fee in a fake settlement report is a TEST FIXTURE supplied by the caller; no fee formula exists.
 *
 * The routes exist only where fake providers are allowed — never in production — and need an administrator with the
 * matching finance permission.
 */
class FakeFinanceController extends Controller
{
    private const SCENARIOS = ['PROCESSED', 'FAILED', 'REVERSED', 'NEXT_PROCESSED', 'NEXT_PROCESSING', 'NEXT_FAILED', 'NEXT_TIMEOUT', 'NEXT_TIMEOUT_LOST', 'NEXT_REJECTED', 'NEXT_RATE_LIMITED', 'PROVIDER_DOWN', 'PROVIDER_UP'];

    public function __construct(private readonly PayoutProviderManager $providers, private readonly PayoutService $payouts) {}

    public function payout(Request $request, string $payout): JsonResponse
    {
        $this->needs($request, Permission::AdminFinancePayoutsExecute);
        $input = $request->validate([
            'scenario' => ['required', 'string', 'in:'.implode(',', self::SCENARIOS)],
            'webhook' => ['sometimes', 'string', 'in:none,deliver,duplicate'],
        ]);
        $found = RestaurantPayout::query()->where('public_id', $payout)->where('provider', FakePayoutProvider::NAME)->first() ?? throw ApiException::notFound('payout_not_found', 'This payout does not exist.');
        $fake = $this->fake();
        $result = ['scenario' => $input['scenario'], 'provider' => FakePayoutProvider::NAME, 'money_moved' => false, 'webhooks' => []];

        switch ($input['scenario']) {
            case 'PROCESSED':
            case 'FAILED':
            case 'REVERSED':
                // The bank finishes, fails or returns the payout the fake provider holds for our key.
                $held = $fake->findPayoutByKey((string) $found->idempotency_key);
                if ($held === null) {
                    throw new ApiException(409, 'payout_not_with_provider', 'The fake provider holds no payout for this record yet.');
                }
                $settled = $fake->settle($held->reference, strtolower($input['scenario']));
                $send = function (?string $eventId = null) use ($fake, $settled, $input, &$result): string {
                    [$body, $headers] = $fake->webhook('payout.'.strtolower($input['scenario']), $settled, $eventId);
                    $result['webhooks'][] = $this->payouts->webhook(FakePayoutProvider::NAME, $body, $headers)['result'];

                    return $headers[FakePayoutProvider::EVENT_ID_HEADER];
                };
                match ($input['webhook'] ?? 'none') {
                    'deliver' => $send(),
                    'duplicate' => $send($send()),
                    default => null,
                };
                $result['provider_payout_reference'] = $settled->reference;
                break;
            case 'NEXT_PROCESSED':
            case 'NEXT_PROCESSING':
            case 'NEXT_FAILED':
                $fake->payoutsBecome(strtolower(substr($input['scenario'], 5)));
                break;
            case 'NEXT_TIMEOUT':
                $fake->failNext('create_payout', ProviderFailure::Timeout);
                break;
            case 'NEXT_TIMEOUT_LOST':
                // The provider makes the payout and the answer never arrives.
                $fake->failNext('create_payout', ProviderFailure::Timeout, 1, true);
                break;
            case 'NEXT_REJECTED':
                $fake->failNext('create_payout', ProviderFailure::Rejected);
                break;
            case 'NEXT_RATE_LIMITED':
                $fake->failNext('create_payout', ProviderFailure::RateLimited);
                break;
            case 'PROVIDER_DOWN':
                foreach (['create_payout', 'payout', 'find_payout'] as $operation) {
                    $fake->failNext($operation, ProviderFailure::Unavailable, 1000);
                }
                break;
            case 'PROVIDER_UP':
                $fake->recover();
                $fake->payoutsBecome(null);
                break;
        }

        return response()->json(['simulation' => $result]);
    }

    /**
     * A TEST payout destination for a location, at the fake payout provider.
     */
    public function payoutProfile(Request $request): JsonResponse
    {
        $this->needs($request, Permission::AdminFinancePayoutsExecute);
        $input = $request->validate([
            'restaurant' => ['required', 'string', 'uuid'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', [RestaurantPayoutProfile::VERIFIED, RestaurantPayoutProfile::PENDING_VERIFICATION, RestaurantPayoutProfile::SUSPENDED])],
        ]);
        $location = RestaurantLocation::query()->where('public_id', $input['restaurant'])->first() ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
        $status = (string) ($input['status'] ?? RestaurantPayoutProfile::VERIFIED);
        $profile = RestaurantPayoutProfile::query()->where('location_id', $location->getKey())->where('provider', FakePayoutProvider::NAME)->first() ?? new RestaurantPayoutProfile;
        if ($profile->provider_account_reference === null) {
            $account = $this->fake()->registerAccount();
            $profile->forceFill(['organization_id' => $location->organization_id, 'location_id' => $location->getKey(), 'provider' => FakePayoutProvider::NAME, 'provider_account_reference' => $account['reference'], 'destination_mask' => $account['mask']]);
        }
        $profile->forceFill(['status' => $status, 'provider_status' => $status === RestaurantPayoutProfile::VERIFIED ? 'activated' : strtolower($status), 'verified_at' => $status === RestaurantPayoutProfile::VERIFIED ? now() : null, 'checked_at' => now(), 'version' => (int) ($profile->version ?? 0) + 1])->save();

        return response()->json(['payout_profile' => ['restaurant' => (string) $location->public_id, 'provider' => FakePayoutProvider::NAME, 'status' => $status, 'destination' => $profile->destination_mask, 'test_fixture' => true, 'kyc' => 'NOT_PERFORMED (fake provider)']], 201);
    }

    /**
     * The fake payment provider "settles" a day: its report holds the fake-provider payments and refunds that no
     * report mentions yet, with the fee and tax GIVEN here (basis points of the payment — a test fixture, not a
     * provider's price). The report is only written at the fake provider; importing it is the real import.
     */
    public function providerSettlement(Request $request, ProviderSettlementSources $sources): JsonResponse
    {
        $this->needs($request, Permission::AdminFinanceReconciliationManage);
        $input = $request->validate([
            'day' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'fee_bps' => ['required', 'integer', 'between:0,1000'],
            'tax_on_fee_bps' => ['required', 'integer', 'between:0,10000'],
            // What the report should get wrong, to try the reconciliation.
            'scenario' => ['sometimes', 'string', 'in:CLEAN,OMIT_ONE_PAYMENT,WRONG_AMOUNT,UNKNOWN_PAYMENT,WRONG_TOTAL,UNKNOWN_LINE'],
        ]);
        if (! $sources->current() instanceof FakeSettlementSource) {
            throw new ApiException(409, 'fake_provider_not_active', 'The fake payment provider is not the configured provider here.');
        }
        $scenario = (string) ($input['scenario'] ?? 'CLEAN');
        $fee = fn (int $amount): int => intdiv($amount * (int) $input['fee_bps'] + 5000, 10000);
        $payments = PaymentAttempt::query()->where('provider', 'fake')->where('status', PaymentStatus::Verified->value)->whereNotNull('provider_payment_reference')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('provider_settlement_items as i')->where('i.provider', 'fake')->where('i.type', 'PAYMENT')->whereColumn('i.entity_reference', 'payment_attempts.provider_payment_reference'))
            ->orderBy('id')->limit(500)->get()->map(fn (PaymentAttempt $p): array => ['reference' => (string) $p->provider_payment_reference, 'amount' => (int) $p->amount_minor, 'fee' => $f = $fee((int) $p->amount_minor),
                'tax' => intdiv($f * (int) $input['tax_on_fee_bps'] + 5000, 10000), 'currency' => (string) $p->currency])->values()->all();
        $refunds = Refund::query()->where('provider', 'fake')->where('status', RefundStatus::Refunded->value)->whereNotNull('provider_refund_reference')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('provider_settlement_items as i')->where('i.provider', 'fake')->where('i.type', 'REFUND')->whereColumn('i.entity_reference', 'refunds.provider_refund_reference'))
            ->orderBy('id')->limit(500)->get()->map(fn (Refund $r): array => ['reference' => (string) $r->provider_refund_reference, 'payment_reference' => $r->provider_payment_reference, 'amount' => $r->amountMinor(), 'currency' => (string) $r->currency])->values()->all();
        $options = [];
        if ($scenario === 'OMIT_ONE_PAYMENT' && $payments !== []) {
            array_pop($payments);
        } elseif ($scenario === 'WRONG_AMOUNT' && $payments !== []) {
            $payments[0]['amount'] += 100;
        } elseif ($scenario === 'UNKNOWN_PAYMENT') {
            $payments[] = ['reference' => 'fpay_unknown_'.Str::lower(Str::random(8)), 'amount' => 12300, 'fee' => 0, 'tax' => 0, 'currency' => $payments[0]['currency'] ?? 'INR'];
        } elseif ($scenario === 'UNKNOWN_LINE') {
            $options['extra'] = [['type' => 'adjustment', 'credit' => 500, 'amount' => 500]];
        }
        if ($payments === [] && $refunds === []) {
            throw new ApiException(409, 'nothing_to_settle', 'The fake provider has no payment or refund that a report does not already mention.');
        }
        if ($scenario === 'WRONG_TOTAL') {
            // The settlement's amount is reported 1.00 more than its lines add up to.
            $options['amount'] = array_sum(array_map(fn (array $p): int => $p['amount'] - $p['fee'], $payments)) - array_sum(array_column($refunds, 'amount')) + 100;
        }
        $report = (new FakeSettlementSource)->settleDay(CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['day'], 'UTC'), $payments, $refunds, $options);

        return response()->json(['simulation' => ['provider' => 'fake', 'day' => $input['day'], 'scenario' => $scenario, 'settlement' => $report['settlement'], 'amount_minor' => $report['amount'], 'lines' => $report['lines'],
            'payments' => count($payments), 'refunds' => count($refunds), 'fees' => 'TEST FIXTURE supplied by the caller', 'money_moved' => false, 'next' => 'POST /admin/finance/provider-settlements/import']], 201);
    }

    private function fake(): FakePayoutProvider
    {
        $provider = $this->providers->named(FakePayoutProvider::NAME);

        return $provider instanceof FakePayoutProvider ? $provider : throw new ApiException(409, 'fake_provider_not_active', 'The fake payout provider is not the configured provider here.');
    }

    private function needs(Request $request, Permission $permission): void
    {
        $request->user()->can($permission->value) || throw new ApiException(403, 'forbidden', 'This action needs the permission '.$permission->value.'.');
    }
}
