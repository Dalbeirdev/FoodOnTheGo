<?php

namespace App\Contracts\Payment;

use App\Exceptions\ProviderException;
use App\Exceptions\ProviderRefundRejected;
use App\Services\Payment\Data\ProviderDispute;
use App\Services\Payment\Data\ProviderPayment;
use App\Services\Payment\Data\ProviderRefund;
use App\Services\Payment\Data\ProviderRefundRequest;
use App\Services\Payment\Data\ProviderSession;
use App\Services\Payment\Data\ProviderSessionRequest;
use App\Services\Payment\Data\ProviderWebhook;

/**
 * A payment provider as FoodOnTheGo sees one. The application talks to this interface only: no provider's SDK
 * object, state name or field name crosses it. An adapter lives in App\Services\Payment\Providers.
 *
 * Every call that reaches the provider has an explicit timeout and throws ProviderException with a normalized
 * failure. Only the reads are safe to repeat; creating a session is not, and a caller that gets Timeout from it
 * must find out by receipt whether the session exists before trying again.
 */
interface PaymentProvider
{
    /** The provider's name as stored with a payment attempt, e.g. "razorpay". */
    public function name(): string;

    /** TEST, LIVE or FAKE. */
    public function mode(): string;

    public function isFake(): bool;

    /**
     * What is missing before this provider can be used (configuration names, never values). Empty when ready.
     *
     * @return list<string>
     */
    public function missingConfiguration(): array;

    /**
     * The identifier the provider allows in a browser or an app (a publishable key id). Never a secret.
     */
    public function clientKey(): ?string;

    /**
     * Creates the provider-side order / payment session for exactly this amount and currency.
     *
     * @throws ProviderException Timeout = it is NOT known whether the session was created
     */
    public function createSession(ProviderSessionRequest $request): ProviderSession;

    /**
     * The session created with this receipt, or null when the provider has none — how an uncertain creation is settled.
     *
     * @throws ProviderException
     */
    public function findSessionByReceipt(string $receipt): ?ProviderSession;

    /**
     * Every payment the provider has for the session, in the provider's order.
     *
     * @return list<ProviderPayment>
     *
     * @throws ProviderException
     */
    public function paymentsOfSession(string $orderReference): array;

    /**
     * @throws ProviderException
     */
    public function payment(string $paymentReference): ProviderPayment;

    /**
     * Whether the proof the provider handed the customer's device after a payment is genuine for OUR session
     * reference and this payment reference. A pure calculation with the server-held secret; no network.
     */
    public function verifyClientSignature(string $orderReference, string $paymentReference, string $signature): bool;

    /**
     * Whether a webhook body was signed by the provider. The body is the raw request body, byte for byte.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool;

    /** The request header that carries the webhook signature. */
    public function webhookSignatureHeader(): string;

    /**
     * Reads a webhook body the signature of which has already been verified.
     *
     * @param  array<string, string|null>  $headers  lower-case header names
     *
     * @throws \InvalidArgumentException when the body is not a provider event
     */
    public function parseWebhook(string $rawBody, array $headers): ProviderWebhook;

    /**
     * Payment method types this provider is CONFIGURED to offer here — from configuration, never from memory of what
     * the provider can do elsewhere. Null means "whatever the provider's own interface shows for the account".
     *
     * @return list<string>|null
     */
    public function configuredMethods(): ?array;

    /* ------------------------------------------------------------------ refunds and disputes (Module 35) */

    /**
     * Asks the provider to return money of a captured payment — exactly this amount, to the method that paid.
     * The request's receipt / idempotency key makes it safe to send the SAME request again; it is never safe to
     * send a different one while the outcome of the first is not known.
     *
     * @throws ProviderRefundRejected the provider refused it (a normalized reason)
     * @throws ProviderException Timeout / Unavailable = it is NOT known whether the refund was created;
     *                           RateLimited / Misconfigured = it was certainly not created
     */
    public function createRefund(ProviderRefundRequest $request): ProviderRefund;

    /**
     * One refund of a payment, as the provider has it now.
     *
     * @throws ProviderException NotFound when the provider has no such refund on this payment
     */
    public function refund(string $paymentReference, string $refundReference): ProviderRefund;

    /**
     * Every refund the provider has for a payment — how an unanswered creation is found by its receipt.
     *
     * @return list<ProviderRefund>
     *
     * @throws ProviderException
     */
    public function refundsOfPayment(string $paymentReference): array;

    /**
     * A dispute / chargeback as the provider has it now.
     *
     * @throws ProviderException NotFound when the provider has no such dispute
     */
    public function dispute(string $disputeReference): ProviderDispute;
}
