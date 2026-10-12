<?php

namespace App\Services\Finance\Payout;

use App\Contracts\Finance\PayoutProvider;
use App\Enums\ProviderFailure;
use App\Enums\ProviderPayoutState;
use App\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JsonException;

/**
 * DEVELOPMENT ONLY — a payout provider that moves no money (Module 36). It exists so that the payout lifecycle
 * (submission, idempotency, a lost answer, processing, paid, failed, reversed, webhooks) can be built and tested on
 * a machine without a provider account. It is refused in production, and it proves nothing about a real provider:
 * LIVE PAYOUT = DISABLED.
 *
 * It behaves the way the payout service must be able to rely on: a payout is created once per idempotency key, an
 * answer can be lost after the payout was created, and its webhooks are signed.
 */
final class FakePayoutProvider implements PayoutProvider
{
    public const NAME = 'fake';

    public const SIGNATURE_HEADER = 'x-fake-payout-signature';

    public const EVENT_ID_HEADER = 'x-fake-payout-event-id';

    private const WEBHOOK_KEY = 'fake-payout-provider-webhook-key';

    private const PREFIX = 'payouts:fake:';

    private const TTL = 172800;

    private const OPERATIONS = ['create_payout', 'payout', 'find_payout', 'cancel_payout'];

    public function name(): string
    {
        return self::NAME;
    }

    public function mode(): string
    {
        return 'test';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function missingConfiguration(): array
    {
        return [];
    }

    public function createPayout(PayoutRequest $request): ProviderPayout
    {
        $this->maybeFail('create_payout', lost: false);
        $known = Cache::get(self::PREFIX.'key:'.$request->idempotencyKey);
        if (is_string($known) && is_array($record = Cache::get(self::PREFIX.'payout:'.$known))) {
            // The same key again: the same payout — never a second one.
            return self::map($record);
        }
        if ($this->accountStatus($request->accountReference) !== 'activated') {
            throw ProviderException::rejected(self::NAME, 'the destination account is not active');
        }
        $outcome = Cache::get(self::PREFIX.'outcome');
        $state = match (is_string($outcome) ? $outcome : (string) config('finance.payouts.fake.outcome', 'processing')) {
            'processed' => 'processed',
            'failed' => 'failed',
            default => 'processing',
        };
        $record = [
            'reference' => 'fpout_'.Str::lower(Str::random(14)),
            'status' => $state,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            'key' => $request->idempotencyKey,
            'account' => $request->accountReference,
            'our_reference' => $request->reference,
            'failure_code' => $state === 'failed' ? 'beneficiary_bank_declined' : null,
            'utr' => $state === 'processed' ? 'FAKEUTR'.random_int(100000000, 999999999) : null,
            'created_at' => now()->getTimestamp(),
        ];
        Cache::put(self::PREFIX.'payout:'.$record['reference'], $record, self::TTL);
        Cache::put(self::PREFIX.'key:'.$request->idempotencyKey, $record['reference'], self::TTL);
        // The provider made the payout — and the answer never arrives.
        $this->maybeFail('create_payout', lost: true);

        return self::map($record);
    }

    public function getPayout(string $payoutReference): ProviderPayout
    {
        $this->maybeFail('payout', lost: false);
        $record = Cache::get(self::PREFIX.'payout:'.$payoutReference);

        return is_array($record) ? self::map($record) : throw ProviderException::notFound(self::NAME);
    }

    public function findPayoutByKey(string $idempotencyKey): ?ProviderPayout
    {
        $this->maybeFail('find_payout', lost: false);
        $reference = Cache::get(self::PREFIX.'key:'.$idempotencyKey);
        $record = is_string($reference) ? Cache::get(self::PREFIX.'payout:'.$reference) : null;

        return is_array($record) ? self::map($record) : null;
    }

    public function cancelPayout(string $payoutReference): ProviderPayout
    {
        $this->maybeFail('cancel_payout', lost: false);
        $record = Cache::get(self::PREFIX.'payout:'.$payoutReference);
        if (! is_array($record)) {
            throw ProviderException::notFound(self::NAME);
        }
        if ($record['status'] !== 'processing') {
            throw ProviderException::rejected(self::NAME, 'the payout can no longer be cancelled');
        }

        return $this->settle($payoutReference, 'cancelled');
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        return is_string($signature) && $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, self::WEBHOOK_KEY), $signature);
    }

    public function webhookSignatureHeader(): string
    {
        return self::SIGNATURE_HEADER;
    }

    public function parseWebhook(string $rawBody, array $headers): ProviderPayoutWebhook
    {
        try {
            $event = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ProviderException::rejected(self::NAME, 'the webhook body is not JSON');
        }
        $eventId = $headers[self::EVENT_ID_HEADER] ?? null;
        if (! is_array($event) || ! is_string($eventId) || $eventId === '' || ! is_string($event['event'] ?? null)) {
            throw ProviderException::rejected(self::NAME, 'the webhook is incomplete');
        }
        $reference = $event['payload']['payout']['id'] ?? null;

        return new ProviderPayoutWebhook(
            mb_substr($eventId, 0, 80),
            mb_substr((string) $event['event'], 0, 48),
            is_string($reference) ? mb_substr($reference, 0, 64) : null,
            is_int($event['created_at'] ?? null) ? CarbonImmutable::createFromTimestampUTC($event['created_at']) : null,
        );
    }

    public function accountStatus(string $accountReference): ?string
    {
        $record = Cache::get(self::PREFIX.'account:'.$accountReference);
        if (is_array($record)) {
            return (string) $record['status'];
        }

        // A reference the fake provider issued in a test fixture or a seed: its accounts all start with "fa_".
        return str_starts_with($accountReference, 'fa_') ? 'activated' : null;
    }

    /* ------------------------------------------------------------------ the fake provider's own side (tests, the development simulator) */

    /**
     * Registers a TEST destination: returns the token and the mask a real provider would hand back after its own
     * onboarding. No bank detail goes in or comes out.
     *
     * @return array{reference: string, mask: string, status: string}
     */
    public function registerAccount(string $status = 'activated'): array
    {
        $account = ['reference' => 'fa_'.Str::lower(Str::random(14)), 'mask' => '•••• '.random_int(1000, 9999), 'status' => $status];
        Cache::put(self::PREFIX.'account:'.$account['reference'], $account, self::TTL * 30);

        return $account;
    }

    /**
     * The bank finishes, fails or returns a payout.
     *
     * @param  'processed'|'failed'|'reversed'|'cancelled'|'processing'  $outcome
     */
    public function settle(string $payoutReference, string $outcome, ?string $failureCode = null): ProviderPayout
    {
        $record = Cache::get(self::PREFIX.'payout:'.$payoutReference);
        if (! is_array($record)) {
            throw ProviderException::notFound(self::NAME);
        }
        $record['status'] = $outcome;
        $record['failure_code'] = in_array($outcome, ['failed', 'reversed', 'cancelled'], true) ? ($failureCode ?? 'beneficiary_bank_declined') : null;
        $record['utr'] = in_array($outcome, ['processed', 'reversed'], true) ? ($record['utr'] ?? 'FAKEUTR'.random_int(100000000, 999999999)) : $record['utr'];
        Cache::put(self::PREFIX.'payout:'.$payoutReference, $record, self::TTL);

        return self::map($record);
    }

    /**
     * Changes what the provider holds for a payout — to try a provider record that disagrees with ours.
     */
    public function tamper(string $payoutReference, int $amountMinor, ?string $currency = null): void
    {
        $record = Cache::get(self::PREFIX.'payout:'.$payoutReference);
        if (is_array($record)) {
            Cache::put(self::PREFIX.'payout:'.$payoutReference, [...$record, 'amount' => $amountMinor, 'currency' => $currency ?? $record['currency']], self::TTL);
        }
    }

    /** What new payouts become: "processing", "processed", "failed" — or null for the configured default. */
    public function payoutsBecome(?string $outcome): void
    {
        $outcome === null ? Cache::forget(self::PREFIX.'outcome') : Cache::put(self::PREFIX.'outcome', $outcome, self::TTL);
    }

    /**
     * The next call(s) of an operation fail: create_payout, payout, find_payout, cancel_payout. With $lostAnswer a
     * create_payout times out AFTER the payout was created.
     */
    public function failNext(string $operation, ProviderFailure $failure, int $times = 1, bool $lostAnswer = false): void
    {
        Cache::put(self::PREFIX.'fail:'.$operation.($lostAnswer ? ':lost' : ''), ['failure' => $failure->value, 'times' => max(1, $times)], self::TTL);
    }

    public function recover(): void
    {
        foreach (self::OPERATIONS as $operation) {
            Cache::forget(self::PREFIX.'fail:'.$operation);
            Cache::forget(self::PREFIX.'fail:'.$operation.':lost');
        }
    }

    /**
     * What the provider would send: the raw body with its signature and event id.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function webhook(string $type, ProviderPayout $payout, ?string $eventId = null, ?int $createdAt = null): array
    {
        $body = json_encode([
            'event' => $type,
            'created_at' => $createdAt ?? now()->getTimestamp(),
            'payload' => ['payout' => ['id' => $payout->reference, 'status' => $payout->status]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [$body, [
            self::SIGNATURE_HEADER => hash_hmac('sha256', $body, self::WEBHOOK_KEY),
            self::EVENT_ID_HEADER => $eventId ?? 'fakepoevt_'.Str::lower(Str::random(16)),
        ]];
    }

    private function maybeFail(string $operation, bool $lost): void
    {
        $key = self::PREFIX.'fail:'.$operation.($lost ? ':lost' : '');
        $script = Cache::get($key);
        if (! is_array($script)) {
            return;
        }
        $script['times'] > 1 ? Cache::put($key, [...$script, 'times' => $script['times'] - 1], self::TTL) : Cache::forget($key);

        throw new ProviderException(ProviderFailure::from($script['failure']), self::NAME);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function map(array $record): ProviderPayout
    {
        return new ProviderPayout(
            (string) $record['reference'],
            match ($record['status']) {
                'processing' => ProviderPayoutState::Processing,
                'processed' => ProviderPayoutState::Processed,
                'failed', 'cancelled' => ProviderPayoutState::Failed,
                'reversed' => ProviderPayoutState::Reversed,
                default => ProviderPayoutState::Unknown,
            },
            (int) $record['amount'],
            (string) $record['currency'],
            (string) $record['status'],
            (string) $record['key'],
            $record['failure_code'] ?? null,
            ($record['failure_code'] ?? null) === null ? null : 'The fake provider did not send this payout.',
            $record['utr'] ?? null,
        );
    }
}
