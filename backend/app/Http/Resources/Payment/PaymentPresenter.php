<?php

namespace App\Http\Resources\Payment;

use App\Enums\CheckoutStatus;
use App\Enums\PaymentFulfillmentStatus;
use App\Enums\PaymentNextAction;
use App\Enums\PaymentStatus;
use App\Http\Resources\Refund\RefundPresenter;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\PaymentAttemptStore;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Refund\RefundEligibilityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A payment as its owner may see it — and, separately, as an administrator may.
 *
 * The customer's view says what happened in words that blame nobody, what to do next (decided here, so that Web and
 * Android never guess from a status), and — only while the customer can still pay — what the provider's interface
 * needs to open: the provider's public key id, its order reference and the amount. Never a secret, never another
 * customer's data, and never an order: a VERIFIED payment says the order is still to be created.
 */
final class PaymentPresenter
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly PaymentAttemptStore $attempts,
        private readonly RefundPresenter $refunds,
        private readonly RefundEligibilityService $refundEligibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(PaymentAttempt $attempt, Customer $customer, ?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        if ($attempt->status === PaymentStatus::Verified && $attempt->fulfillment_status === PaymentFulfillmentStatus::OrderPending) {
            $attempt->refresh(); // the order may have been created after this request's commit (the queue is synchronous locally)
        }
        $session = $attempt->status->payable() && ! $attempt->expired($at) ? $this->session($attempt, $customer, $at) : null;
        [$action, $wait] = $this->next($attempt, $session !== null, $at);
        $failure = $attempt->failure_category;

        return [
            'id' => (string) $attempt->public_id,
            'checkout_id' => (string) $attempt->checkout_public_id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status->value,
            'amount' => $attempt->amount()->toArray(),
            'provider' => ['name' => (string) $attempt->provider, 'mode' => (string) $attempt->provider_mode],
            'requested_method_type' => $attempt->requested_method_type,
            'method' => $attempt->method_type === null ? null : ['type' => (string) $attempt->method_type, 'summary' => (object) ($attempt->method_summary ?? [])],
            'failure' => $failure === null || $attempt->status === PaymentStatus::Verified ? null : [
                'category' => $failure->value,
                'code' => $attempt->failure_code,
                'message' => $failure->customerMessage(),
                'retryable' => in_array($action, [PaymentNextAction::RetryPayment, PaymentNextAction::SelectAnotherMethod], true),
            ],
            'message' => $this->message($attempt),
            'next_action' => $action->value,
            'retry_after_seconds' => $wait,
            'provider_session' => $session,
            'fulfillment' => [
                'status' => $attempt->fulfillment_status->value,
                // The order the payment became (Module 31) — created on the server from the verified payment. Until it
                // exists there is no order to show, and none is invented: a client waits for order_number.
                'order_id' => $attempt->order?->public_id === null ? null : (string) $attempt->order->public_id,
                'order_number' => $attempt->order?->order_number,
            ],
            'support_reference' => $attempt->supportReference(),
            'expires_at' => self::utc($attempt->expires_at),
            'server_time' => self::utc($at),
            'created_at' => self::utc($attempt->created_at),
            'updated_at' => self::utc($attempt->updated_at),
            'verified_at' => self::utc($attempt->verified_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(PaymentAttempt $attempt): array
    {
        $customer = $attempt->customer;
        $location = $attempt->location;

        return [
            'id' => (string) $attempt->public_id,
            'support_reference' => $attempt->supportReference(),
            'checkout_id' => (string) $attempt->checkout_public_id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status->value,
            'status_reason' => $attempt->status_reason,
            'fulfillment_status' => $attempt->fulfillment_status->value,
            'fulfillment_reason' => $attempt->fulfillment_reason,
            // The two things an operator must not miss: money that needs a person, and a paid checkout without an order.
            'needs_attention' => $attempt->status === PaymentStatus::ReviewRequired || $attempt->fulfillment_status === PaymentFulfillmentStatus::RecoveryRequired,
            'verified_without_order' => $attempt->status === PaymentStatus::Verified && $attempt->fulfillment_status !== PaymentFulfillmentStatus::OrderCreated,
            'amount' => $attempt->amount()->toArray(),
            'provider' => (string) $attempt->provider,
            'provider_mode' => (string) $attempt->provider_mode,
            'method_type' => $attempt->method_type,
            'requested_method_type' => $attempt->requested_method_type,
            'failure_category' => $attempt->failure_category?->value,
            'failure_code' => $attempt->failure_code,
            'restaurant' => ['id' => $location?->public_id, 'name' => (string) $attempt->restaurant_name],
            'market' => $attempt->market?->slug,
            // A customer is a reference and a masked number here: the payment screens are not a customer directory.
            'customer' => ['id' => (string) $attempt->customer_public_id, 'phone_masked' => self::maskPhone($customer?->phone_e164)],
            'created_at' => self::utc($attempt->created_at),
            'verified_at' => self::utc($attempt->verified_at),
            'expires_at' => self::utc($attempt->expires_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailForAdmin(PaymentAttempt $attempt): array
    {
        return $this->forAdmin($attempt) + [
            'provider_references' => ['order' => $attempt->provider_order_reference, 'payment' => $attempt->provider_payment_reference, 'receipt' => (string) $attempt->provider_receipt],
            'provider_state' => ['order' => $attempt->provider_order_status, 'payment' => $attempt->provider_payment_status, 'error_code' => $attempt->provider_error_code, 'error_reason' => $attempt->provider_error_reason],
            'method_summary' => (object) ($attempt->method_summary ?? []),
            'verification' => [
                'client_outcome' => $attempt->client_outcome,
                'client_reported_at' => self::utc($attempt->client_reported_at),
                'signature_verified_at' => self::utc($attempt->signature_verified_at),
                'signature_failures' => (int) $attempt->signature_failures,
            ],
            'checkout' => ['id' => (string) $attempt->checkout_public_id, 'version' => (int) $attempt->checkout_version, 'pricing_fingerprint' => (string) $attempt->pricing_fingerprint, 'status' => $attempt->checkout?->status->value, 'expires_at' => self::utc($attempt->checkout?->expires_at)],
            'reconciliation' => ['runs' => (int) $attempt->reconcile_runs, 'last_at' => self::utc($attempt->last_reconciled_at), 'next_at' => self::utc($attempt->next_reconcile_at)],
            'timeline' => $attempt->events->map(fn (PaymentAttemptEvent $e): array => [
                'at' => self::utc($e->occurred_at), 'type' => (string) $e->type, 'source' => (string) $e->source, 'from' => $e->from_status, 'to' => $e->to_status, 'detail' => (object) ($e->detail ?? []),
            ])->all(),
            'webhook_events' => $attempt->webhookEvents->map(fn (PaymentWebhookEvent $e): array => [
                'id' => (string) $e->public_id, 'type' => (string) $e->event_type, 'normalized_type' => $e->normalized_type, 'received_at' => self::utc($e->received_at), 'processed_at' => self::utc($e->processed_at),
                'processing_status' => (string) $e->processing_status, 'outcome' => $e->outcome, 'deliveries' => (int) $e->deliveries, 'failure_reason' => $e->failure_reason,
            ])->all(),
            // Module 35: the refunds of this payment and what it can still give back. The payment itself stays what it was.
            'refunds' => $this->refunds->listForAdmin($attempt->refunds),
            'refund_balance' => RefundPresenter::balance($attempt->refundBalance, (string) $attempt->currency, $attempt->status === PaymentStatus::Verified || $attempt->status_reason === 'DUPLICATE_PAYMENT' ? (int) $attempt->amount_minor : 0),
            'disputes' => $this->refunds->disputesOf($attempt),
            // "refund": a refund of THIS PAYMENT can be asked for — only for a payment that never became an order (an order
            // is refunded through the order). Nothing here sets a status: no "mark paid", no "mark refunded".
            'actions' => ['mark_paid' => false, 'refund' => $this->refundEligibility->forPayment($attempt)[0]->eligible, 'mark_refunded' => 'NOT_PROVIDED'],
        ];
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * What the provider's own interface needs. Returned only to the owner and only while the attempt can be paid.
     *
     * @return array<string, mixed>|null
     */
    private function session(PaymentAttempt $attempt, Customer $customer, CarbonImmutable $at): ?array
    {
        $provider = $this->providers->named((string) $attempt->provider);
        if ($provider === null || $attempt->provider_order_reference === null) {
            return null;
        }

        return [
            'provider' => $provider->name(),
            'mode' => $provider->mode(),
            // The provider's PUBLIC key id — the only provider value a browser or an app may hold.
            'key_id' => $provider->clientKey(),
            'order_reference' => (string) $attempt->provider_order_reference,
            'amount' => $attempt->amount()->toArray(),
            'name' => (string) config('payments.display.name'),
            'description' => (string) config('payments.display.description'),
            'theme_color' => (string) config('payments.display.theme_color'),
            'checkout_script' => $provider->name() === 'razorpay' ? (string) config('services.razorpay.checkout_script') : null,
            // The account's own verified contact details, so the customer does not type them again. Not used for anything else.
            'prefill' => ['name' => $customer->name, 'contact' => (string) $customer->phone_e164, 'email' => $customer->email],
            'preferred_method' => $attempt->requested_method_type,
            'methods' => $provider->configuredMethods(),
            'timeout_seconds' => max(1, (int) $at->diffInSeconds($attempt->expires_at)),
        ];
    }

    /**
     * @return array{0: PaymentNextAction, 1: int|null}
     */
    private function next(PaymentAttempt $attempt, bool $payable, CarbonImmutable $at): array
    {
        return match ($attempt->status) {
            PaymentStatus::Created => [PaymentNextAction::Wait, 2],
            PaymentStatus::ProviderReady => $payable ? [PaymentNextAction::OpenProvider, null] : [PaymentNextAction::RefreshStatus, 3],
            PaymentStatus::Processing => [PaymentNextAction::Wait, 3],
            PaymentStatus::Pending, PaymentStatus::Authorized => [PaymentNextAction::Wait, 5],
            PaymentStatus::Unknown => [PaymentNextAction::RefreshStatus, 10],
            PaymentStatus::Verified => [PaymentNextAction::ProceedToOrder, null],
            PaymentStatus::ReviewRequired => [PaymentNextAction::ContactSupport, null],
            PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Expired => [$this->canPayAgain($attempt, $at) ? ($attempt->failure_category?->nextAction() ?? PaymentNextAction::RetryPayment) : PaymentNextAction::ReturnToCheckout, null],
        };
    }

    /**
     * Whether the same checkout can take another attempt right now.
     */
    private function canPayAgain(PaymentAttempt $attempt, CarbonImmutable $at): bool
    {
        $checkout = $attempt->checkout;
        if ($checkout === null || $checkout->status !== CheckoutStatus::Ready || $at->greaterThanOrEqualTo($checkout->expires_at)) {
            return false;
        }
        $windowEnds = CarbonImmutable::instance($checkout->confirmed_at ?? $at)->addMinutes(max(1, (int) config('payments.window_minutes')));

        return $at->lessThan($windowEnds) && $this->attempts->countOf((string) $attempt->checkout_public_id) < max(1, (int) config('payments.attempt.max_per_checkout'));
    }

    private function message(PaymentAttempt $attempt): ?string
    {
        return match ($attempt->status) {
            PaymentStatus::Created, PaymentStatus::ProviderReady => null,
            PaymentStatus::Processing => 'We\'re confirming your payment.',
            PaymentStatus::Pending, PaymentStatus::Authorized => 'We\'re confirming your payment. This can take a moment.',
            PaymentStatus::Unknown => 'We could not confirm your payment yet. Please do not pay again — we are checking.',
            PaymentStatus::Verified => match ($attempt->fulfillment_status) {
                PaymentFulfillmentStatus::RecoveryRequired => 'Payment received. We could not confirm your order yet — you do not need to pay again.',
                PaymentFulfillmentStatus::OrderCreated => 'Payment received. Your order is placed.',
                default => 'Payment received. We\'re confirming your order.',
            },
            PaymentStatus::ReviewRequired => 'We are checking this payment. Please do not pay again.',
            PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Expired => $attempt->failure_category?->customerMessage() ?? 'The payment could not be completed.',
        };
    }

    private static function maskPhone(?string $phone): ?string
    {
        return $phone === null || strlen($phone) < 6 ? null : substr($phone, 0, 3).str_repeat('*', max(0, strlen($phone) - 7)).substr($phone, -4);
    }

    private static function utc(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->toIso8601String();
    }
}
