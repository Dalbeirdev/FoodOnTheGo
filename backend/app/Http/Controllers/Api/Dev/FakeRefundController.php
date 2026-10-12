<?php

namespace App\Http\Controllers\Api\Dev;

use App\Enums\DisputeStatus;
use App\Enums\Permission;
use App\Enums\ProviderFailure;
use App\Exceptions\ApiException;
use App\Exceptions\ProviderRefundRejected;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\Providers\FakePaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DEVELOPMENT ONLY — the fake payment provider's side of a refund and of a dispute (Module 35).
 *
 * A real provider settles a refund in its own time and tells us with a webhook; a customer's bank opens a dispute.
 * The fake provider has this endpoint for both, so the refund flows can be tried on this machine without a provider
 * account. NO MONEY MOVES.
 *
 * It does not shortcut anything: it only changes the FAKE PROVIDER's record, scripts what the fake provider answers
 * next, or sends what a provider would send (a signed webhook, through the same verification as a real one). Whether
 * a refund becomes REFUNDED is still decided by the refund domain from the provider's record, exactly as for a real
 * provider — there is no way here to set a refund's status.
 *
 * The routes exist only where fake providers are allowed — never in production — and need an administrator who may
 * issue refunds.
 */
class FakeRefundController extends Controller
{
    private const SCENARIOS = ['PROCESSED', 'FAILED', 'NEXT_PROCESSED', 'NEXT_PENDING', 'NEXT_FAILED', 'NEXT_TIMEOUT', 'NEXT_TIMEOUT_LOST', 'NEXT_REJECTED', 'NEXT_RATE_LIMITED', 'PROVIDER_DOWN', 'PROVIDER_UP'];

    private const WEBHOOKS = ['none', 'deliver', 'duplicate', 'out_of_order'];

    public function __construct(private readonly PaymentProviderManager $providers, private readonly PaymentWebhookService $webhooks) {}

    public function simulate(Request $request, string $refund): JsonResponse
    {
        $request->user()->can(Permission::AdminRefundsIssue->value) || throw new ApiException(403, 'forbidden', 'This action needs the permission to issue refunds.');
        $input = $request->validate([
            'scenario' => ['required', 'string', 'in:'.implode(',', self::SCENARIOS)],
            'webhook' => ['sometimes', 'string', 'in:'.implode(',', self::WEBHOOKS)],
            'reason' => ['sometimes', 'string', 'in:'.implode(',', [ProviderRefundRejected::INSUFFICIENT_BALANCE, ProviderRefundRejected::METHOD_NOT_SUPPORTED, ProviderRefundRejected::REJECTED, ProviderRefundRejected::AMOUNT_EXCEEDS_AVAILABLE])],
        ]);
        $found = Refund::query()->where('public_id', $refund)->where('provider', FakePaymentProvider::NAME)->first()
            ?? throw ApiException::notFound('refund_not_found', 'This refund does not exist.');
        $fake = $this->fake();
        $webhook = (string) ($input['webhook'] ?? 'none');
        $result = ['scenario' => $input['scenario'], 'provider' => FakePaymentProvider::NAME, 'money_moved' => false, 'webhooks' => []];

        switch ($input['scenario']) {
            case 'PROCESSED':
            case 'FAILED':
                // The provider (the bank) finishes — or fails — the refund it holds for our receipt.
                $held = $found->provider_receipt === null ? null : $fake->refundByReceipt((string) $found->provider_receipt);
                if ($held === null) {
                    throw new ApiException(409, 'refund_not_with_provider', 'The fake provider holds no refund for this record yet. Execute it first.');
                }
                $settled = $fake->settleRefund($held->reference, $input['scenario'] === 'PROCESSED' ? 'processed' : 'failed');
                $type = $input['scenario'] === 'PROCESSED' ? 'refund.processed' : 'refund.failed';
                $send = function (string $type, $refund, ?string $eventId = null) use ($fake, &$result): string {
                    [$body, $headers] = $fake->refundWebhook($type, $refund, $eventId);
                    $result['webhooks'][] = ['type' => $type, 'result' => $this->webhooks->accept(FakePaymentProvider::NAME, $body, $headers)];

                    return $headers[FakePaymentProvider::EVENT_ID_HEADER];
                };
                match ($webhook) {
                    'deliver' => $send($type, $settled),
                    'duplicate' => $send($type, $settled, $send($type, $settled)),
                    // The final event first, the earlier "created" one afterwards.
                    'out_of_order' => [$send($type, $settled), $send('refund.created', $held)],
                    default => null,
                };
                $result['provider_refund_reference'] = $settled->reference;
                break;
            case 'NEXT_PROCESSED':
            case 'NEXT_PENDING':
            case 'NEXT_FAILED':
                $fake->refundsBecome(strtolower(substr($input['scenario'], 5)));
                break;
            case 'NEXT_TIMEOUT':
                $fake->failNext('create_refund', ProviderFailure::Timeout);
                break;
            case 'NEXT_TIMEOUT_LOST':
                // The provider creates the refund and the answer never arrives.
                $fake->failNext('create_refund', ProviderFailure::Timeout, 1, true);
                break;
            case 'NEXT_RATE_LIMITED':
                $fake->failNext('create_refund', ProviderFailure::RateLimited);
                break;
            case 'NEXT_REJECTED':
                $fake->rejectNextRefund((string) ($input['reason'] ?? ProviderRefundRejected::INSUFFICIENT_BALANCE));
                break;
            case 'PROVIDER_DOWN':
                foreach (['create_refund', 'refund', 'refunds'] as $operation) {
                    $fake->failNext($operation, ProviderFailure::Unavailable, 1000);
                }
                break;
            case 'PROVIDER_UP':
                $fake->recover();
                $fake->refundsBecome(null);
                break;
        }

        return response()->json(['simulation' => $result]);
    }

    /**
     * A customer's bank disputes a payment, and the dispute moves on — through signed provider webhooks.
     */
    public function dispute(Request $request, string $payment): JsonResponse
    {
        $request->user()->can(Permission::AdminRefundsIssue->value) || throw new ApiException(403, 'forbidden', 'This action needs the permission to issue refunds.');
        $input = $request->validate([
            'scenario' => ['required', 'string', 'in:OPEN,UNDER_REVIEW,WON,LOST,CLOSED'],
            'dispute_reference' => ['required_unless:scenario,OPEN', 'string', 'max:64'],
        ]);
        $attempt = PaymentAttempt::query()->where('public_id', $payment)->where('provider', FakePaymentProvider::NAME)->whereNotNull('provider_payment_reference')->first()
            ?? throw ApiException::notFound('payment_not_found', 'This payment does not exist.');
        $fake = $this->fake();
        if ($input['scenario'] === 'OPEN') {
            $dispute = $fake->openDispute((string) $attempt->provider_payment_reference);
            $type = 'payment.dispute.created';
        } else {
            $status = DisputeStatus::from($input['scenario']);
            $dispute = $fake->settleDispute((string) $input['dispute_reference'], $status);
            $type = 'payment.dispute.'.strtolower($status->value);
        }
        [$body, $headers] = $fake->disputeWebhook($type, $dispute);

        return response()->json(['simulation' => [
            'scenario' => $input['scenario'], 'provider' => FakePaymentProvider::NAME, 'money_moved' => false,
            'dispute_reference' => $dispute->reference, 'webhooks' => [['type' => $type, 'result' => $this->webhooks->accept(FakePaymentProvider::NAME, $body, $headers)]],
        ]]);
    }

    private function fake(): FakePaymentProvider
    {
        $fake = $this->providers->named(FakePaymentProvider::NAME);

        return $fake instanceof FakePaymentProvider ? $fake : throw ApiException::notFound('refund_not_found', 'This refund does not exist.');
    }
}
