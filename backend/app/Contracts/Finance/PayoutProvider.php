<?php

namespace App\Contracts\Finance;

use App\Exceptions\ProviderException;
use App\Services\Finance\Payout\PayoutRequest;
use App\Services\Finance\Payout\ProviderPayout;
use App\Services\Finance\Payout\ProviderPayoutWebhook;

/**
 * A provider that sends money to a restaurant (Module 36) — a DIFFERENT abstraction from PaymentProvider even when
 * the vendor is the same: its product, its account, its lifecycle and its API are its own.
 *
 * The only implementation is the development FakePayoutProvider. No real payout adapter exists: restaurant payouts
 * need a provider product this project does not have (PENDING PROVIDER ACCOUNT ENABLEMENT), restaurant KYC / bank
 * onboarding and a finance / legal decision. LIVE PAYOUT = DISABLED.
 */
interface PayoutProvider
{
    public function name(): string;

    /** "test" or "live". */
    public function mode(): string;

    public function isFake(): bool;

    /**
     * Names of the settings that are missing — never a value.
     *
     * @return list<string>
     */
    public function missingConfiguration(): array;

    /**
     * Asks the provider to send the money. The request's idempotency key is sent with EVERY try of the same payout:
     * a repeated key must return the payout that exists, never make a second one.
     *
     * @throws ProviderException Timeout / Unavailable when the outcome is not known; Rejected when it was refused
     */
    public function createPayout(PayoutRequest $request): ProviderPayout;

    /**
     * @throws ProviderException NotFound when the provider has no such payout
     */
    public function getPayout(string $payoutReference): ProviderPayout;

    /**
     * The payout the provider made for one of our idempotency keys, or null when it has none: how a submission
     * without an answer is settled.
     */
    public function findPayoutByKey(string $idempotencyKey): ?ProviderPayout;

    /**
     * Cancels a payout the provider has not started (where the provider supports it).
     *
     * @throws ProviderException Rejected when it can no longer be cancelled
     */
    public function cancelPayout(string $payoutReference): ProviderPayout;

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool;

    public function webhookSignatureHeader(): string;

    /**
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public function parseWebhook(string $rawBody, array $headers): ProviderPayoutWebhook;

    /**
     * The provider's own state of a destination account, in its words (e.g. "activated"), or null when unknown.
     */
    public function accountStatus(string $accountReference): ?string;
}
